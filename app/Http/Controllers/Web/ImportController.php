<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\AuthorizationService;
use App\Services\ImportService;

/** Importacion masiva de credenciales (art. 18). */
final class ImportController extends Controller
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private ImportService $import, private AuthorizationService $gate)
    {
    }

    public function index(Request $request): Response
    {
        $this->gate->require('import.credentials');
        return $this->view('credentials/import', [
            'pageTitle' => 'Importar credenciales',
            'columns'   => $this->import->templateColumns(),
            'preview'   => null,
        ]);
    }

    public function template(Request $request): Response
    {
        $this->gate->require('import.credentials');
        return Response::html($this->import->templateCsv())
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="plantilla-credenciales.csv"');
    }

    public function preview(Request $request): Response
    {
        $this->gate->require('import.credentials');
        $content = $this->readUpload($request);
        $preview = $this->import->preview($content);

        return $this->view('credentials/import', [
            'pageTitle' => 'Importar credenciales',
            'columns'   => $this->import->templateColumns(),
            'preview'   => $preview,
            // El contenido se devuelve al formulario para confirmar sin
            // guardar el archivo en el servidor (contiene contrasenas).
            'payload'   => base64_encode($content),
        ]);
    }

    public function execute(Request $request): Response
    {
        $this->gate->require('import.credentials');
        $encoded = (string) $request->input('payload', '');
        $content = base64_decode($encoded, true);
        if ($content === false || $content === '') {
            throw HttpException::badRequest('No hay datos para importar. Vuelva a cargar el archivo.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw HttpException::badRequest('El archivo supera el tamano permitido.');
        }

        $result = $this->import->execute($content);
        $this->success(sprintf(
            'Importacion finalizada. Credenciales creadas: %d. Omitidas: %d.',
            $result['created'], $result['skipped']
        ));
        return $this->redirect('/credenciales');
    }

    private function readUpload(Request $request): string
    {
        $file = $request->file('archivo');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest('Debe seleccionar un archivo CSV valido.');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw HttpException::badRequest('El archivo supera los 2 MB permitidos.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw HttpException::badRequest('La carga del archivo no es valida.');
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            throw HttpException::badRequest('Solo se admiten archivos CSV. Exporte su Excel a CSV primero.');
        }

        $content = (string) file_get_contents($tmp);
        // El archivo temporal contiene contrasenas en claro: se elimina ya.
        @unlink($tmp);

        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @iconv('ISO-8859-1', 'UTF-8//TRANSLIT', $content);
            $content   = $converted !== false ? $converted : $content;
        }
        return $content;
    }
}
