<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\CredentialService;

/**
 * Unico punto de la API que devuelve secretos en claro.
 *
 * Deliberadamente separado del CRUD: GET /api/v1/credentials JAMAS
 * incluye contrasenas (art. 45). Aqui cada llamada exige permiso,
 * asignacion vigente, step-up y queda registrada.
 */
final class SecretController extends Controller
{
    public function __construct(private CredentialService $credentials)
    {
    }

    /** POST /api/v1/credenciales/{id}/secreto */
    public function reveal(Request $request, array $params): Response
    {
        $result = $this->credentials->revealSecret(
            (int) $params['id'],
            $request->string('field', 'password'),
            $request->bool('copy') ? 'copy' : 'view'
        );

        return $this->json([
            'secret'     => $result['secret'],
            'field'      => $result['field'],
            'version'    => $result['version'],
            'is_current' => $result['is_current'],
            // Vida util sugerida en pantalla antes de volver a ocultarlo.
            'ttl'        => 30,
        ])->withHeader('Cache-Control', 'no-store, private')
          ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** POST /api/v1/credenciales/{id}/secreto/historial/{version} */
    public function revealHistoric(Request $request, array $params): Response
    {
        $result = $this->credentials->revealSecret(
            (int) $params['id'],
            $request->string('field', 'password'),
            'history_view',
            (int) $params['version']
        );

        return $this->json([
            'secret'       => $result['secret'],
            'version'      => $result['version'],
            'generated_at' => $result['generated_at'],
            'ttl'          => 30,
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    /** GET /api/v1/credenciales/{id}/recuperacion */
    public function recovery(Request $request, array $params): Response
    {
        return $this->json($this->credentials->recoveryInfo((int) $params['id']))
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
