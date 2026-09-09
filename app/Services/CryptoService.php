<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use RuntimeException;

/**
 * =====================================================================
 *  NUCLEO CRIPTOGRAFICO
 * =====================================================================
 *
 *  Modelo: cifrado de sobre (envelope encryption) en tres niveles.
 *
 *    MASTER KEY  (32 bytes aleatorios)
 *        vive en .env, FUERA del webroot, permisos 0600.
 *        Nunca se guarda en base de datos, nunca se registra en logs.
 *              |
 *              |  HKDF-SHA256(master, salt = encryption_keys.salt,
 *              |              info = "SCGCA:KEK:v{version}")
 *              v
 *    KEK        (clave de cifrado de claves, por version del llavero)
 *        se deriva en memoria en cada arranque; jamas se persiste.
 *              |
 *              |  AES-256-GCM(KEK) -> wrapped_dek
 *              v
 *    DEK        (clave de datos, UNICA POR SECRETO, 32 bytes aleatorios)
 *              |
 *              |  AES-256-GCM(DEK, nonce, AAD)
 *              v
 *    CIPHERTEXT del secreto  ->  tabla credential_secrets
 *
 *  Consecuencias de diseno:
 *   - Un volcado de la base de datos NO revela ningun secreto: sin la
 *     master key del .env el material es indescifrable.
 *   - Cada secreto tiene su propia DEK: comprometer una no compromete
 *     las demas y la reutilizacion de nonce es imposible en la practica.
 *   - El AAD ata el criptograma a (credencial, campo, version). Mover una
 *     fila de una credencial a otra invalida la autenticacion GCM.
 *   - La rotacion de la master key solo exige re-envolver las DEK
 *     (wrapped_dek), no redescifrar todos los secretos... con la salvedad
 *     de que aqui se re-cifra el sobre completo por simplicidad auditable.
 *
 *  Se utiliza cifrado REVERSIBLE (no hash) porque el sistema debe poder
 *  mostrar la contrasena a un usuario autorizado. Las contrasenas DE ACCESO
 *  AL PROPIO SISTEMA, en cambio, se guardan como hash irreversible.
 */
final class CryptoService
{
    private const CIPHER      = 'aes-256-gcm';
    private const TAG_LENGTH  = 16;
    private const NONCE_BYTES = 12;
    private const KEY_BYTES   = 32;
    private const HKDF_HASH   = 'sha256';

    private ?string $masterKey = null;
    /** @var array<int,string> cache de KEK derivadas por version */
    private array $kekCache = [];
    private ?int $activeVersion = null;

    public function __construct(private Database $db)
    {
    }

    // -----------------------------------------------------------------
    //  Material de clave
    // -----------------------------------------------------------------

    private function masterKey(): string
    {
        if ($this->masterKey !== null) {
            return $this->masterKey;
        }
        $raw = (string) Env::get('APP_MASTER_KEY', '');
        if ($raw === '') {
            throw new RuntimeException(
                'APP_MASTER_KEY no esta configurada. Ejecute: php bin/console.php key:generate'
            );
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== self::KEY_BYTES) {
            throw new RuntimeException('APP_MASTER_KEY invalida: se esperan 32 bytes en base64.');
        }
        $this->masterKey = $decoded;
        return $decoded;
    }

    /** Pimienta del lado del servidor para el prehash de contrasenas de acceso. */
    private function pepper(): string
    {
        $raw = (string) Env::get('APP_PEPPER', '');
        if ($raw === '') {
            // Derivada de la master key si no se define una pimienta propia.
            return hash_hkdf(self::HKDF_HASH, $this->masterKey(), 32, 'SCGCA:PEPPER:v1');
        }
        $decoded = base64_decode($raw, true);
        return $decoded !== false && $decoded !== '' ? $decoded : $raw;
    }

    public function activeKeyVersion(): int
    {
        if ($this->activeVersion !== null) {
            return $this->activeVersion;
        }
        $row = $this->db->selectOne(
            'SELECT version FROM encryption_keys WHERE status = ? ORDER BY version DESC LIMIT 1',
            ['active']
        );
        if ($row === null) {
            $this->activeVersion = $this->createKeyVersion();
            return $this->activeVersion;
        }
        $this->activeVersion = (int) $row['version'];
        return $this->activeVersion;
    }

    /** Crea una nueva version en el llavero y la marca como activa. */
    public function createKeyVersion(): int
    {
        return $this->db->transaction(function (Database $db): int {
            $max  = (int) ($db->scalar('SELECT COALESCE(MAX(version), 0) FROM encryption_keys') ?? 0);
            $next = $max + 1;
            $db->execute("UPDATE encryption_keys SET status = 'retired', retired_at = NOW() WHERE status = 'active'");
            $db->insert(
                'INSERT INTO encryption_keys (version, salt, algo, status) VALUES (?, ?, ?, ?)',
                [$next, random_bytes(32), self::CIPHER, 'active']
            );
            $this->activeVersion = $next;
            $this->kekCache      = [];
            return $next;
        });
    }

    private function kek(int $version): string
    {
        if (isset($this->kekCache[$version])) {
            return $this->kekCache[$version];
        }
        $row = $this->db->selectOne('SELECT salt FROM encryption_keys WHERE version = ?', [$version]);
        if ($row === null) {
            throw new RuntimeException('Version de clave desconocida: ' . $version);
        }
        $salt = $row['salt'];
        if (is_resource($salt)) {
            $salt = stream_get_contents($salt);
        }
        $kek = hash_hkdf(self::HKDF_HASH, $this->masterKey(), self::KEY_BYTES, 'SCGCA:KEK:v' . $version, (string) $salt);
        $this->kekCache[$version] = $kek;
        return $kek;
    }

    // -----------------------------------------------------------------
    //  Cifrado / descifrado de secretos
    // -----------------------------------------------------------------

    /**
     * Cifra un secreto y devuelve el sobre completo listo para persistir.
     *
     * @return array{key_version:int,algo:string,ciphertext:string,nonce:string,tag:string,wrapped_dek:string,dek_nonce:string,dek_tag:string}
     */
    public function encrypt(string $plaintext, string $aad = ''): array
    {
        if ($plaintext === '') {
            throw new RuntimeException('No se puede cifrar un valor vacio.');
        }
        $version = $this->activeKeyVersion();
        $kek     = $this->kek($version);

        $dek       = random_bytes(self::KEY_BYTES);
        $nonce     = random_bytes(self::NONCE_BYTES);
        $tag       = '';
        $cipher    = openssl_encrypt($plaintext, self::CIPHER, $dek, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_LENGTH);
        if ($cipher === false) {
            throw new RuntimeException('Fallo el cifrado del secreto.');
        }

        $dekNonce = random_bytes(self::NONCE_BYTES);
        $dekTag   = '';
        $wrapped  = openssl_encrypt($dek, self::CIPHER, $kek, OPENSSL_RAW_DATA, $dekNonce, $dekTag, 'SCGCA:DEK:v' . $version, self::TAG_LENGTH);
        if ($wrapped === false) {
            throw new RuntimeException('Fallo el envoltorio de la clave de datos.');
        }

        // Borrado best-effort del material de clave en memoria.
        $this->wipe($dek);

        return [
            'key_version' => $version,
            'algo'        => self::CIPHER,
            'ciphertext'  => $cipher,
            'nonce'       => $nonce,
            'tag'         => $tag,
            'wrapped_dek' => $wrapped,
            'dek_nonce'   => $dekNonce,
            'dek_tag'     => $dekTag,
        ];
    }

    /**
     * Descifra un sobre. Devuelve null si la autenticacion GCM falla
     * (dato manipulado, AAD incorrecto o clave equivocada).
     *
     * @param array<string,mixed> $envelope
     */
    public function decrypt(array $envelope, string $aad = ''): ?string
    {
        $version = (int) ($envelope['key_version'] ?? 0);
        if ($version <= 0) {
            return null;
        }
        $kek = $this->kek($version);

        $wrapped  = $this->bin($envelope['wrapped_dek'] ?? '');
        $dekNonce = $this->bin($envelope['dek_nonce'] ?? '');
        $dekTag   = $this->bin($envelope['dek_tag'] ?? '');

        $dek = openssl_decrypt($wrapped, self::CIPHER, $kek, OPENSSL_RAW_DATA, $dekNonce, $dekTag, 'SCGCA:DEK:v' . $version);
        if ($dek === false) {
            return null;
        }

        $plain = openssl_decrypt(
            $this->bin($envelope['ciphertext'] ?? ''),
            self::CIPHER,
            $dek,
            OPENSSL_RAW_DATA,
            $this->bin($envelope['nonce'] ?? ''),
            $this->bin($envelope['tag'] ?? ''),
            $aad
        );
        $this->wipe($dek);

        return $plain === false ? null : $plain;
    }

    /**
     * Re-cifra un sobre existente con la version de clave activa.
     * Se usa en la rotacion de la clave maestra o del llavero.
     */
    public function rewrap(array $envelope, string $aad): ?array
    {
        $plain = $this->decrypt($envelope, $aad);
        if ($plain === null) {
            return null;
        }
        $new = $this->encrypt($plain, $aad);
        $this->wipe($plain);
        return $new;
    }

    /**
     * AAD canonico. Ata el criptograma a su ubicacion logica exacta.
     */
    public function aad(string $entity, int|string $entityId, string $field, int $version = 1): string
    {
        return sprintf('SCGCA|%s|%s|%s|v%d', $entity, (string) $entityId, $field, $version);
    }

    /**
     * Huella determinista de un secreto (HMAC con clave derivada).
     * Permite detectar contrasenas repetidas o reutilizadas SIN descifrar
     * nada y sin poder invertir el valor.
     */
    public function fingerprint(string $plaintext): string
    {
        $key = hash_hkdf(self::HKDF_HASH, $this->masterKey(), 32, 'SCGCA:FINGERPRINT:v1');
        return hash_hmac('sha256', $plaintext, $key);
    }

    // -----------------------------------------------------------------
    //  Contrasenas de acceso al sistema (hash irreversible)
    // -----------------------------------------------------------------

    /**
     * Hash de la contrasena con la que un usuario entra AL SISTEMA.
     * Aqui si es irreversible, por diseno.
     *
     * Construccion: bcrypt( base64( HMAC-SHA256(password, pepper) ) )
     *  - el prehash HMAC evita el truncamiento a 72 bytes de bcrypt
     *    y el problema del byte nulo;
     *  - la pimienta vive en el .env: un volcado de la tabla `users` no
     *    basta para atacar los hashes por fuerza bruta;
     *  - si el binario dispone de Argon2id se usa preferentemente.
     */
    public function hashPassword(string $password): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($this->prehash($password), PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
            $algo = 'argon2id-hmac';
        } else {
            $hash = password_hash($this->prehash($password), PASSWORD_BCRYPT, ['cost' => 12]);
            $algo = 'bcrypt-hmac';
        }
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('No fue posible generar el hash de la contrasena.');
        }
        return ['hash' => $hash, 'algo' => $algo];
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        // password_verify es de tiempo constante respecto al hash.
        return password_verify($this->prehash($password), $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2])
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    private function prehash(string $password): string
    {
        return base64_encode(hash_hmac('sha256', $password, $this->pepper(), true));
    }

    // -----------------------------------------------------------------
    //  Utilidades
    // -----------------------------------------------------------------

    /** Token opaco de alta entropia (sesiones, restablecimientos, reportes). */
    public function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public function hashToken(string $token): string
    {
        // SHA-256 basta: el token ya tiene 256 bits de entropia, no hay
        // espacio de busqueda que atacar por diccionario.
        return hash('sha256', $token);
    }

    public function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /** Convierte flujos/recursos de PDO (BLOB) a cadena binaria. */
    private function bin(mixed $value): string
    {
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }
        return (string) $value;
    }

    /** Sobrescribe una variable en memoria (mitigacion best-effort). */
    private function wipe(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            /** @psalm-suppress UndefinedFunction */
            sodium_memzero($value);
            return;
        }
        $len   = strlen($value);
        $value = $len > 0 ? str_repeat("\0", $len) : '';
        unset($value);
    }
}
