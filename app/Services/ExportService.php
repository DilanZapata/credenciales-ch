<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\AuditRepository;
use App\Repositories\CredentialRepository;
use App\Repositories\ExportRepository;
use App\Support\XlsxWriter;

/**
 * Generacion de reportes en Excel (art. 33 a 42).
 *
 * Controles aplicados a CADA exportacion:
 *   1. Permiso de exportacion (y permiso adicional para incluir secretos).
 *   2. Confirmacion explicita cuando el archivo llevara contrasenas.
 *   3. Reautenticacion reciente (step-up) para reportes con secretos.
 *   4. Limitador de frecuencia y tope de registros.
 *   5. Registro completo en export_reports + export_report_items +
 *      secret_access_log (una entrada por credencial revelada) + auditoria.
 *   6. El archivo se escribe FUERA del webroot, con nombre aleatorio,
 *      permisos 0600 y vida util corta; se borra al descargarlo y un
 *      proceso de mantenimiento purga los abandonados.
 */
final class ExportService
{
    public function __construct(
        private CredentialRepository $credentials,
        private AuditRepository $auditRepo,
        private ExportRepository $exports,
        private CryptoService $crypto,
        private AuthorizationService $gate,
        private AuthContext $context,
        private AuditService $audit,
        private SettingsService $settings,
        private RateLimiter $limiter
    ) {
    }

    /**
     * @param array<string,mixed> $options
     *        type: inventory|full_credentials|history|audit
     *        filters: array de filtros
     *        ids: seleccion manual de credenciales
     *        include_secrets: bool
     *        confirm: bool  (confirmacion explicita del cuadro de advertencia)
     * @return array{uuid:string,file_name:string,record_count:int,included_secrets:bool}
     */
    public function generate(array $options): array
    {
        $type           = (string) ($options['type'] ?? 'inventory');
        $includeSecrets = (bool) ($options['include_secrets'] ?? false);
        $filters        = is_array($options['filters'] ?? null) ? $options['filters'] : [];
        $ids            = array_values(array_filter(array_map('intval', (array) ($options['ids'] ?? []))));

        // --- 1. Permisos -------------------------------------------------
        $this->gate->require('reports.view');
        $this->gate->require('export.reports');   // EXPORTAR_REPORTES
        match ($type) {
            'inventory', 'full_credentials' => $this->gate->require('export.credentials'),
            'history'                       => $this->gate->require('export.history'),
            'audit'                         => $this->gate->require('audit.export'),
            default                         => throw HttpException::badRequest('Tipo de reporte no valido.'),
        };

        if ($includeSecrets) {
            if ($type !== 'full_credentials') {
                throw HttpException::badRequest('Solo el reporte de credenciales completas admite contrasenas.');
            }
            $this->gate->require('export.credentials.secrets');
            if (!($options['confirm'] ?? false)) {
                throw new ValidationException([
                    'confirm' => 'Debe confirmar explicitamente la generacion de un archivo con contrasenas reales.',
                ]);
            }
            // --- 3. Step-up obligatorio ----------------------------------
            $this->gate->requireStepUp('export');
        }

        // Un consultor jamas realiza exportaciones globales: si su alcance
        // esta limitado, el reporte se restringe a lo asignado.
        $scopeUserId = $this->gate->credentialScopeUserId();
        if ($scopeUserId !== null) {
            $this->gate->require('export.credentials');
        }

        // --- 4. Frecuencia y volumen -------------------------------------
        $bucket = 'export:' . ($this->context->id() ?? 0);
        if (!$this->limiter->attempt($bucket, $includeSecrets ? 5 : 20, 3600)) {
            $this->audit->log(AuditService::EXPORT_DENIED, 'export', null, $type, 'denied',
                ['motivo' => 'limite de exportaciones por hora'], 'warning');
            throw HttpException::tooManyRequests('Ha alcanzado el limite de exportaciones por hora.');
        }

        $maxRecords = max(1, $this->settings->int('exports.max_records', 5000));

        // --- 5. Registro previo (la exportacion queda trazada aunque falle)
        $uuid     = $this->uuid();
        $reportId = $this->exports->create([
            'uuid'              => $uuid,
            'user_id'           => $this->context->id(),
            'actor_national_id' => $this->context->nationalId(),
            'report_type'       => $type,
            'format'            => 'xlsx',
            'filters'           => $this->describeFilters($filters, $ids),
            'included_secrets'  => $includeSecrets ? 1 : 0,
            'ip_address'        => $this->context->ip(),
            'user_agent'        => $this->context->userAgent(),
            'session_id'        => $this->context->sessionId(),
            'retention_minutes' => max(1, $this->settings->int('exports.retention_minutes', 15)),
        ]);

        try {
            $writer = new XlsxWriter();

            $recordCount   = 0;
            $credentialIds = [];

            switch ($type) {
                case 'inventory':
                    [$recordCount, $credentialIds] = $this->buildInventorySheet($writer, $filters, $ids, $scopeUserId, $maxRecords);
                    break;
                case 'full_credentials':
                    [$recordCount, $credentialIds] = $this->buildFullSheet($writer, $filters, $ids, $scopeUserId, $maxRecords, $includeSecrets, $reportId);
                    break;
                case 'history':
                    $recordCount = $this->buildHistorySheet($writer, $filters, $ids, $maxRecords);
                    $credentialIds = $ids;
                    break;
                case 'audit':
                    $recordCount = $this->buildAuditSheet($writer, $filters, $maxRecords);
                    break;
            }

            $this->appendMetadataSheet($writer, $type, $recordCount, $includeSecrets, $filters, $ids, $uuid);

            $fileName    = $this->buildFileName($type, $includeSecrets);
            $storagePath = $this->storagePath() . '/' . $uuid . '.xlsx';
            $writer->save($storagePath);

            $this->exports->markReady(
                $reportId,
                $fileName,
                $storagePath,
                hash_file('sha256', $storagePath) ?: '',
                (int) filesize($storagePath),
                $recordCount
            );
            if ($credentialIds !== []) {
                $this->exports->attachCredentials($reportId, $credentialIds);
            }

            $this->audit->log(
                AuditService::EXPORT_GENERATED, 'export', $uuid, $this->typeLabel($type), 'success',
                [
                    'tipo'               => $type,
                    'registros'          => $recordCount,
                    'incluye_contrasenas'=> $includeSecrets,
                    'filtros'            => $this->describeFilters($filters, $ids),
                ],
                $includeSecrets ? 'critical' : 'notice'
            );

            if ($includeSecrets) {
                $this->audit->securityEvent(
                    'export_with_secrets',
                    'Exportacion con contrasenas reales',
                    sprintf('%s exporto %d credenciales incluyendo contrasenas.', $this->context->fullName(), $recordCount),
                    'high'
                );
            }

            return [
                'uuid'             => $uuid,
                'file_name'        => $fileName,
                'record_count'     => $recordCount,
                'included_secrets' => $includeSecrets,
            ];
        } catch (\Throwable $e) {
            $this->exports->markFailed($reportId);
            throw $e;
        }
    }

    /**
     * Entrega el archivo generado. Solo puede descargarlo quien lo genero,
     * una vez, y antes de que expire.
     *
     * @return array{path:string,file_name:string}
     */
    public function download(string $uuid): array
    {
        $report = $this->exports->findByUuid($uuid);
        if ($report === null) {
            throw HttpException::notFound('El reporte no existe.');
        }
        if ((int) $report['user_id'] !== (int) $this->context->id()) {
            $this->audit->log(AuditService::EXPORT_DENIED, 'export', $uuid, null, 'denied',
                ['motivo' => 'intento de descarga de un reporte ajeno'], 'critical');
            $this->audit->securityEvent('export_idor', 'Intento de descarga de reporte ajeno',
                'Un usuario intento descargar un reporte generado por otra persona.', 'high');
            throw HttpException::notFound('El reporte no existe.');
        }
        if ($report['status'] === 'purged' || $report['storage_path'] === null
            || strtotime((string) $report['expires_at']) < time()) {
            throw HttpException::conflict('El archivo ya expiro. Genere el reporte nuevamente.');
        }
        if (!is_readable((string) $report['storage_path'])) {
            $this->exports->markPurged((int) $report['id']);
            throw HttpException::conflict('El archivo ya no esta disponible.');
        }

        $this->exports->markDownloaded((int) $report['id']);
        $this->audit->log(AuditService::EXPORT_DOWNLOADED, 'export', $uuid, (string) $report['file_name'],
            'success', ['incluye_contrasenas' => (bool) $report['included_secrets']],
            $report['included_secrets'] ? 'warning' : 'info');

        return [
            'path'      => (string) $report['storage_path'],
            'file_name' => (string) $report['file_name'],
        ];
    }

    /** Mantenimiento: elimina del disco los archivos vencidos. */
    public function purgeExpiredFiles(): int
    {
        $purged = 0;
        foreach ($this->exports->expiredWithFiles() as $row) {
            $path = (string) $row['storage_path'];
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
            $this->exports->markPurged((int) $row['id']);
            $purged++;
        }
        return $purged + $this->purgeOrphanFiles();
    }

    /**
     * Barrido de seguridad del directorio de exportaciones.
     *
     * Elimina TODO archivo cuya antiguedad supere la ventana de retencion,
     * exista o no un registro que lo respalde. Cubre el caso de los archivos
     * huerfanos: si por una restauracion de base de datos, un fallo a mitad
     * de la generacion o una migracion se pierde la fila de export_reports,
     * el archivo —que puede contener contrasenas reales— no debe quedarse
     * en el servidor para siempre (art. 39).
     */
    public function purgeOrphanFiles(): int
    {
        $directory = $this->storagePath();
        $retention = max(1, $this->settings->int('exports.retention_minutes', 15));
        $cutoff    = time() - ($retention * 60);
        $purged    = 0;

        foreach (glob($directory . '/*.xlsx') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $modified = @filemtime($file);
            if ($modified !== false && $modified < $cutoff) {
                @unlink($file);
                $purged++;
            }
        }

        if ($purged > 0) {
            \App\Core\Logger::info('Barrido de exportaciones huerfanas', ['archivos_eliminados' => $purged]);
        }
        return $purged;
    }

    // -----------------------------------------------------------------
    //  Construccion de hojas
    // -----------------------------------------------------------------

    /** @return array{0:int,1:array<int,int>} */
    private function buildInventorySheet(XlsxWriter $writer, array $filters, array $ids, ?int $scopeUserId, int $max): array
    {
        $rows        = $this->fetchCredentials($filters, $ids, $scopeUserId, $max, false);
        $sheetRows   = [];
        $identifiers = [];

        foreach ($rows as $row) {
            $identifiers[] = (int) $row['id'];
            $sheetRows[]   = [
                (int) $row['id'],
                $row['system_name'],
                $row['name'],
                $row['category_name'],
                $this->resourceTypeLabel((string) $row['resource_type']),
                $row['url'],
                $row['ip_address'],
                $row['port'] !== null ? (int) $row['port'] : '',
                $row['hostname'],
                $row['platform'],
                $row['provider'],
                $row['company_name'],
                $row['location_name'],
                $row['department_name'],
                $row['username'],
                $row['email'],
                $row['domain'],
                $row['auth_method'],
                $row['owner_name'],
                $this->statusLabel((string) $row['status']),
                $this->formatDate($row['created_at']),
                $this->formatDate($row['updated_at']),
                $this->formatDate($row['password_changed_at']),
                $this->formatDate($row['next_rotation_at']),
                $this->formatDate($row['expires_at']),
                ['v' => $row['observations'], 's' => XlsxWriter::STYLE_WRAP],
            ];
        }

        $writer->addSheet('Inventario', [
            ['label' => 'ID', 'width' => 8],
            ['label' => 'Sistema', 'width' => 30],
            ['label' => 'Credencial', 'width' => 30],
            ['label' => 'Categoria', 'width' => 18],
            ['label' => 'Tipo de recurso', 'width' => 18],
            ['label' => 'URL', 'width' => 34],
            ['label' => 'Direccion IP', 'width' => 15],
            ['label' => 'Puerto', 'width' => 10],
            ['label' => 'Servidor', 'width' => 22],
            ['label' => 'Plataforma', 'width' => 18],
            ['label' => 'Proveedor', 'width' => 18],
            ['label' => 'Empresa', 'width' => 22],
            ['label' => 'Sede', 'width' => 18],
            ['label' => 'Departamento', 'width' => 20],
            ['label' => 'Usuario', 'width' => 24],
            ['label' => 'Correo', 'width' => 26],
            ['label' => 'Dominio', 'width' => 18],
            ['label' => 'Metodo de autenticacion', 'width' => 22],
            ['label' => 'Responsable', 'width' => 24],
            ['label' => 'Estado', 'width' => 14],
            ['label' => 'Fecha de creacion', 'width' => 18],
            ['label' => 'Ultima actualizacion', 'width' => 18],
            ['label' => 'Ultimo cambio de contrasena', 'width' => 20],
            ['label' => 'Proxima rotacion', 'width' => 18],
            ['label' => 'Fecha de vencimiento', 'width' => 18],
            ['label' => 'Observaciones', 'width' => 40],
        ], $sheetRows);

        return [count($sheetRows), $identifiers];
    }

    /** @return array{0:int,1:array<int,int>} */
    private function buildFullSheet(
        XlsxWriter $writer,
        array $filters,
        array $ids,
        ?int $scopeUserId,
        int $max,
        bool $includeSecrets,
        int $reportId
    ): array {
        $canSeeRecovery = $this->gate->can('credentials.recovery.view');
        $rows           = $this->fetchCredentials($filters, $ids, $scopeUserId, $max, $canSeeRecovery);

        $sheetRows   = [];
        $identifiers = [];

        foreach ($rows as $row) {
            $credentialId  = (int) $row['id'];
            $identifiers[] = $credentialId;

            $password   = '';
            $pin        = '';
            $accessCode = '';

            if ($includeSecrets) {
                $password   = $this->decryptFor($credentialId, 'password', $reportId);
                $pin        = $this->decryptFor($credentialId, 'pin', $reportId);
                $accessCode = $this->decryptFor($credentialId, 'access_code', $reportId);
            }

            $sheetRows[] = [
                (int) $row['id'],
                $row['system_name'],
                $row['name'],
                $row['category_name'],
                $this->resourceTypeLabel((string) $row['resource_type']),
                $row['url'],
                $row['ip_address'],
                $row['port'] !== null ? (int) $row['port'] : '',
                $row['hostname'],
                $row['platform'],
                $row['provider'],
                $row['company_name'],
                $row['location_name'],
                $row['department_name'],
                ['v' => $row['system_description'], 's' => XlsxWriter::STYLE_WRAP],
                $row['username'],
                $row['email'],
                $includeSecrets ? $password : 'No incluida',
                $includeSecrets ? $pin : '',
                $includeSecrets ? $accessCode : '',
                $row['domain'],
                $row['admin_username'],
                $canSeeRecovery ? ($row['recovery_email'] ?? '') : 'Sin permiso',
                $canSeeRecovery ? ($row['recovery_phone'] ?? '') : '',
                $canSeeRecovery ? ($row['recovery_username'] ?? '') : '',
                $canSeeRecovery ? ['v' => $row['recovery_notes'] ?? '', 's' => XlsxWriter::STYLE_WRAP] : '',
                $row['owner_name'],
                $this->formatDate($row['created_at']),
                $this->formatDate($row['updated_at']),
                $this->formatDate($row['password_changed_at']),
                $this->formatDate($row['next_rotation_at']),
                $this->formatDate($row['expires_at']),
                $this->statusLabel((string) $row['status']),
                ['v' => $row['observations'], 's' => XlsxWriter::STYLE_WRAP],
            ];
        }

        $writer->addSheet($includeSecrets ? 'Credenciales (CONFIDENCIAL)' : 'Credenciales', [
            ['label' => 'ID', 'width' => 8],
            ['label' => 'Sistema', 'width' => 30],
            ['label' => 'Credencial', 'width' => 30],
            ['label' => 'Categoria', 'width' => 18],
            ['label' => 'Tipo', 'width' => 16],
            ['label' => 'URL', 'width' => 34],
            ['label' => 'IP', 'width' => 15],
            ['label' => 'Puerto', 'width' => 10],
            ['label' => 'Servidor', 'width' => 22],
            ['label' => 'Plataforma', 'width' => 18],
            ['label' => 'Proveedor', 'width' => 18],
            ['label' => 'Empresa', 'width' => 22],
            ['label' => 'Sede', 'width' => 18],
            ['label' => 'Departamento', 'width' => 20],
            ['label' => 'Descripcion', 'width' => 34],
            ['label' => 'Usuario', 'width' => 24],
            ['label' => 'Correo electronico', 'width' => 28],
            ['label' => 'Contrasena', 'width' => 26],
            ['label' => 'PIN', 'width' => 14],
            ['label' => 'Codigo de acceso', 'width' => 20],
            ['label' => 'Dominio', 'width' => 18],
            ['label' => 'Usuario administrador', 'width' => 22],
            ['label' => 'Correo de recuperacion', 'width' => 28],
            ['label' => 'Telefono de recuperacion', 'width' => 20],
            ['label' => 'Usuario de recuperacion', 'width' => 24],
            ['label' => 'Informacion adicional de recuperacion', 'width' => 34],
            ['label' => 'Responsable', 'width' => 24],
            ['label' => 'Fecha de creacion', 'width' => 18],
            ['label' => 'Ultima actualizacion', 'width' => 18],
            ['label' => 'Ultimo cambio de contrasena', 'width' => 20],
            ['label' => 'Proxima rotacion', 'width' => 18],
            ['label' => 'Fecha de vencimiento', 'width' => 18],
            ['label' => 'Estado', 'width' => 14],
            ['label' => 'Observaciones', 'width' => 40],
        ], $sheetRows);

        return [count($sheetRows), $identifiers];
    }

    private function buildHistorySheet(XlsxWriter $writer, array $filters, array $ids, int $max): int
    {
        $includeSecrets = false; // El historial NUNCA lleva contrasenas antiguas en el reporte.
        $historyFilters = $filters;
        if ($ids !== []) {
            $historyFilters['credential_ids'] = $ids;
        }
        $rows = $this->credentials->history($historyFilters, $max);

        $sheetRows = [];
        foreach ($rows as $row) {
            $sheetRows[] = [
                $this->formatDate($row['performed_at'], true),
                $row['system_name'],
                $row['credential_name'],
                $this->historyActionLabel((string) $row['action']),
                $row['field_changed'],
                $row['old_value'],
                $row['new_value'],
                $row['old_status'],
                $row['new_status'],
                $this->formatDate($row['old_expires_at']),
                $this->formatDate($row['new_expires_at']),
                $row['reason'],
                $row['performed_by_name'],
                $row['performed_by_national_id'],
                $row['ip_address'],
            ];
        }

        $writer->addSheet('Historial', [
            ['label' => 'Fecha y hora', 'width' => 20],
            ['label' => 'Sistema', 'width' => 28],
            ['label' => 'Credencial', 'width' => 28],
            ['label' => 'Accion', 'width' => 22],
            ['label' => 'Campo', 'width' => 20],
            ['label' => 'Valor anterior', 'width' => 26],
            ['label' => 'Valor nuevo', 'width' => 26],
            ['label' => 'Estado anterior', 'width' => 16],
            ['label' => 'Estado nuevo', 'width' => 16],
            ['label' => 'Vencimiento anterior', 'width' => 18],
            ['label' => 'Vencimiento nuevo', 'width' => 18],
            ['label' => 'Motivo', 'width' => 34],
            ['label' => 'Realizado por', 'width' => 26],
            ['label' => 'Cedula', 'width' => 16],
            ['label' => 'IP', 'width' => 15],
        ], $sheetRows);

        return count($sheetRows);
    }

    private function buildAuditSheet(XlsxWriter $writer, array $filters, int $max): int
    {
        $rows      = $this->auditRepo->export($filters, $max);
        $sheetRows = [];
        foreach ($rows as $row) {
            $sheetRows[] = [
                $this->formatDate($row['occurred_at'], true),
                $row['actor_name'],
                $row['actor_national_id'],
                $row['action'],
                $row['entity_type'],
                $row['entity_id'],
                $row['entity_label'],
                $row['result'],
                $row['severity'],
                $row['ip_address'],
                $row['device'],
                $row['route'],
                ['v' => $row['details'], 's' => XlsxWriter::STYLE_WRAP],
            ];
        }

        $writer->addSheet('Auditoria', [
            ['label' => 'Fecha y hora', 'width' => 22],
            ['label' => 'Usuario', 'width' => 26],
            ['label' => 'Cedula', 'width' => 16],
            ['label' => 'Accion', 'width' => 28],
            ['label' => 'Entidad', 'width' => 16],
            ['label' => 'ID', 'width' => 10],
            ['label' => 'Recurso', 'width' => 30],
            ['label' => 'Resultado', 'width' => 12],
            ['label' => 'Severidad', 'width' => 12],
            ['label' => 'IP', 'width' => 15],
            ['label' => 'Dispositivo', 'width' => 24],
            ['label' => 'Ruta', 'width' => 28],
            ['label' => 'Detalle', 'width' => 44],
        ], $sheetRows);

        return count($sheetRows);
    }

    /** Hoja de portada con la trazabilidad del propio reporte. */
    private function appendMetadataSheet(
        XlsxWriter $writer,
        string $type,
        int $recordCount,
        bool $includeSecrets,
        array $filters,
        array $ids,
        string $uuid
    ): void {
        $rows = [
            ['Identificador del reporte', $uuid],
            ['Tipo de reporte', $this->typeLabel($type)],
            ['Generado por', $this->context->fullName()],
            ['Cedula', (string) $this->context->nationalId()],
            ['Fecha y hora', date('d/m/Y H:i:s')],
            ['Direccion IP', $this->context->ip()],
            ['Dispositivo', $this->context->device()],
            ['Registros incluidos', $recordCount],
            ['Incluye contrasenas', $includeSecrets ? 'SI' : 'NO'],
            ['Filtros aplicados', $this->describeFiltersText($filters, $ids)],
            ['', ''],
            [
                ['v' => 'AVISO DE CONFIDENCIALIDAD', 's' => XlsxWriter::STYLE_ALERT],
                ['v' => $includeSecrets
                    ? 'Este archivo contiene CONTRASENAS REALES. Su divulgacion compromete la seguridad de la empresa. '
                      . 'Almacenelo cifrado, no lo envie por correo ni por mensajeria, y eliminelo en cuanto deje de necesitarlo. '
                      . 'Su generacion quedo registrada en la auditoria del sistema.'
                    : 'Este archivo contiene informacion interna de la empresa. Su generacion quedo registrada en la auditoria del sistema.',
                  's' => XlsxWriter::STYLE_WRAP],
            ],
        ];

        $writer->addSheet('Informacion del reporte', [
            ['label' => 'Concepto', 'width' => 30],
            ['label' => 'Valor', 'width' => 90],
        ], $rows, false, false);
    }

    // -----------------------------------------------------------------
    //  Auxiliares
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function fetchCredentials(array $filters, array $ids, ?int $scopeUserId, int $max, bool $includeRecovery): array
    {
        if ($ids !== []) {
            $ids = array_slice($ids, 0, $max);
            return $this->credentials->findManyForReport($ids, $includeRecovery, $scopeUserId);
        }
        $matching = $this->credentials->idsMatching($filters, $scopeUserId, $max);
        if ($matching === []) {
            return [];
        }
        return $this->credentials->findManyForReport($matching, $includeRecovery, $scopeUserId);
    }

    /**
     * Descifra un secreto para el reporte y deja constancia individual en
     * secret_access_log: se puede responder "quien exporto esta credencial".
     */
    private function decryptFor(int $credentialId, string $field, int $reportId): string
    {
        $row = $this->credentials->currentSecret($credentialId, $field);
        if ($row === null) {
            return '';
        }
        $plain = $this->crypto->decrypt(
            $row,
            $this->crypto->aad('credential', $credentialId, $field, (int) $row['version'])
        );
        if ($plain === null) {
            $this->audit->logSecretAccess($credentialId, 'export', (int) $row['id'], $field,
                (int) $row['version'], 'denied', 'fallo de descifrado', $reportId);
            return '[ERROR DE DESCIFRADO]';
        }
        $this->audit->logSecretAccess($credentialId, 'export', (int) $row['id'], $field,
            (int) $row['version'], 'success', null, $reportId);
        return $plain;
    }

    private function storagePath(): string
    {
        $path = (string) Config::get('paths.exports');
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        return rtrim($path, '/');
    }

    /** El nombre nunca revela contenido sensible (art. 39). */
    private function buildFileName(string $type, bool $includeSecrets): string
    {
        $prefix = match ($type) {
            'inventory'        => 'inventario-credenciales',
            'full_credentials' => $includeSecrets ? 'credenciales-confidencial' : 'credenciales',
            'history'          => 'historial-credenciales',
            'audit'            => 'auditoria',
            default            => 'reporte',
        };
        return $prefix . '-' . date('Ymd-His') . '.xlsx';
    }

    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** @return array<string,mixed> */
    private function describeFilters(array $filters, array $ids): array
    {
        $clean = array_filter($filters, static fn ($v) => $v !== '' && $v !== null && $v !== []);
        if ($ids !== []) {
            $clean['seleccion_manual'] = count($ids);
        }
        return $clean;
    }

    private function describeFiltersText(array $filters, array $ids): string
    {
        $described = $this->describeFilters($filters, $ids);
        if ($described === []) {
            return 'Todas las credenciales (sin filtros)';
        }
        $parts = [];
        foreach ($described as $key => $value) {
            $parts[] = $key . ': ' . (is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value);
        }
        return implode(' | ', $parts);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'inventory'        => 'Inventario de credenciales (sin contrasenas)',
            'full_credentials' => 'Credenciales completas',
            'history'          => 'Historial de credenciales',
            'audit'            => 'Registro de auditoria',
            default            => $type,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active'   => 'Activa',
            'inactive' => 'Inactiva',
            'expired'  => 'Vencida',
            'revoked'  => 'Revocada',
            'archived' => 'Archivada',
            default    => $status,
        };
    }

    private function resourceTypeLabel(string $type): string
    {
        return match ($type) {
            'web'         => 'Sitio web',
            'application' => 'Aplicacion',
            'software'    => 'Software',
            'computer'    => 'Computador',
            'server'      => 'Servidor',
            'email'       => 'Correo electronico',
            'network'     => 'Red',
            'cloud'       => 'Servicio en la nube',
            'social'      => 'Red social',
            'banking'     => 'Banca / financiero',
            'license'     => 'Licencia',
            'database'    => 'Base de datos',
            default       => 'Otro',
        };
    }

    private function historyActionLabel(string $action): string
    {
        return match ($action) {
            'created'          => 'Creacion',
            'updated'          => 'Actualizacion',
            'password_rotated' => 'Cambio de contrasena',
            'status_changed'   => 'Cambio de estado',
            'assigned'         => 'Asignacion de acceso',
            'revoked'          => 'Revocacion de acceso',
            'deleted'          => 'Baja',
            'restored'         => 'Reactivacion',
            default            => $action,
        };
    }

    private function formatDate(mixed $value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return (string) $value;
        }
        return date($withTime ? 'd/m/Y H:i:s' : 'd/m/Y', $timestamp);
    }
}
