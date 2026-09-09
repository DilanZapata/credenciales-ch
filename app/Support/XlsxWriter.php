<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Escritor de archivos .xlsx (SpreadsheetML) sin dependencias externas.
 *
 * Se implementa a proposito sin librerias de terceros: el archivo puede
 * contener contrasenas reales, de modo que se prefiere una superficie de
 * codigo pequena y auditable a arrastrar un arbol de dependencias.
 *
 * Todo texto se escapa para XML; ademas se neutraliza la inyeccion de
 * formulas en hojas de calculo (CSV/XLSX injection): un valor que empieza
 * por = + - @ se antepone con un apostrofo.
 */
final class XlsxWriter
{
    /** @var array<int,array{name:string,columns:array<int,array{label:string,width:float}>,rows:array<int,array<int,mixed>>,freeze:bool,autofilter:bool}> */
    private array $sheets = [];

    public const STYLE_DEFAULT = 0;
    public const STYLE_HEADER  = 1;
    public const STYLE_WRAP    = 2;
    public const STYLE_MUTED   = 3;
    public const STYLE_ALERT   = 4;
    public const STYLE_TITLE   = 5;

    /**
     * @param array<int,array{label:string,width?:float}> $columns
     * @param array<int,array<int,mixed>> $rows valores escalares o ['v'=>mixed,'s'=>int]
     */
    public function addSheet(string $name, array $columns, array $rows, bool $freezeHeader = true, bool $autofilter = true): void
    {
        $normalized = [];
        foreach ($columns as $column) {
            $normalized[] = [
                'label' => (string) ($column['label'] ?? ''),
                'width' => (float) ($column['width'] ?? 18),
            ];
        }
        $this->sheets[] = [
            'name'       => $this->sanitizeSheetName($name),
            'columns'    => $normalized,
            'rows'       => $rows,
            'freeze'     => $freezeHeader,
            'autofilter' => $autofilter,
        ];
    }

    public function save(string $path): void
    {
        if ($this->sheets === []) {
            throw new RuntimeException('El libro no contiene hojas.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No fue posible crear el archivo de reporte.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('docProps/app.xml', $this->appProps());
        $zip->addFromString('docProps/core.xml', $this->coreProps());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', $this->sheetXml($sheet));
        }

        $zip->close();
        @chmod($path, 0600);
    }

    // -----------------------------------------------------------------

    private function sheetXml(array $sheet): string
    {
        $columnCount = max(1, count($sheet['columns']));
        $rowCount    = count($sheet['rows']) + 1;
        $lastColumn  = self::columnLetter($columnCount);

        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<dimension ref="A1:' . $lastColumn . $rowCount . '"/>';
        $xml .= '<sheetViews><sheetView workbookViewId="0" tabSelected="1">';
        if ($sheet['freeze']) {
            $xml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
            $xml .= '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
        }
        $xml .= '</sheetView></sheetViews>';
        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';

        if ($sheet['columns'] !== []) {
            $xml .= '<cols>';
            foreach ($sheet['columns'] as $i => $column) {
                $xml .= sprintf(
                    '<col min="%d" max="%d" width="%.2f" customWidth="1"/>',
                    $i + 1, $i + 1, max(8.0, min(80.0, $column['width']))
                );
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        // Encabezado
        $xml .= '<row r="1" ht="24" customHeight="1" s="' . self::STYLE_HEADER . '" customFormat="1">';
        foreach ($sheet['columns'] as $i => $column) {
            $xml .= $this->cell(self::columnLetter($i + 1) . '1', $column['label'], self::STYLE_HEADER);
        }
        $xml .= '</row>';

        // Datos
        $rowNumber = 1;
        foreach ($sheet['rows'] as $row) {
            $rowNumber++;
            $xml .= '<row r="' . $rowNumber . '">';
            $columnIndex = 0;
            foreach ($row as $value) {
                $columnIndex++;
                $style = self::STYLE_DEFAULT;
                if (is_array($value)) {
                    $style = (int) ($value['s'] ?? self::STYLE_DEFAULT);
                    $value = $value['v'] ?? '';
                }
                $xml .= $this->cell(self::columnLetter($columnIndex) . $rowNumber, $value, $style);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($sheet['autofilter'] && $rowCount > 1) {
            $xml .= '<autoFilter ref="A1:' . $lastColumn . $rowCount . '"/>';
        }
        $xml .= '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
        $xml .= '</worksheet>';

        return $xml;
    }

    private function cell(string $reference, mixed $value, int $style): string
    {
        $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';

        if ($value === null || $value === '') {
            return '<c r="' . $reference . '"' . $styleAttr . '/>';
        }
        if (is_int($value) || is_float($value)) {
            return '<c r="' . $reference . '"' . $styleAttr . '><v>' . $value . '</v></c>';
        }
        if (is_bool($value)) {
            return '<c r="' . $reference . '"' . $styleAttr . ' t="inlineStr"><is><t>' . ($value ? 'Si' : 'No') . '</t></is></c>';
        }

        $text = $this->neutralizeFormula((string) $value);
        return '<c r="' . $reference . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">'
             . $this->escape($text) . '</t></is></c>';
    }

    /**
     * Impide que una celda se interprete como formula al abrir el archivo
     * (ataque de inyeccion de formulas / DDE en Excel y LibreOffice).
     */
    private function neutralizeFormula(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'" . $value;
        }
        return $value;
    }

    private function escape(string $value): string
    {
        // Se eliminan los caracteres de control no permitidos por XML 1.0.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function columnLetter(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters   = chr(65 + $remainder) . $letters;
            $index     = (int) (($index - $remainder - 1) / 26);
        }
        return $letters === '' ? 'A' : $letters;
    }

    private function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $name) ?? 'Hoja';
        return mb_substr(trim($name) ?: 'Hoja', 0, 31);
    }

    // ------------------------- Partes fijas del paquete ----------------

    private function contentTypes(): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        foreach ($this->sheets as $index => $_) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml" '
                  . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $xml .= '</Types>';
        return $xml;
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
              . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                $this->escape($sheet['name']), $index + 1, $index + 1
            );
        }
        $xml .= '</sheets></workbook>';
        return $xml;
    }

    private function workbookRels(): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $count = count($this->sheets);
        foreach ($this->sheets as $index => $_) {
            $xml .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index + 1, $index + 1
            );
        }
        $xml .= sprintf(
            '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
            $count + 1
        );
        $xml .= '</Relationships>';
        return $xml;
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="5">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFB91C1C"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right>'
            . '<top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function coreProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Reporte de credenciales</dc:title>'
            . '<dc:creator>Sistema Corporativo de Gestion de Credenciales</dc:creator>'
            . '<cp:lastModifiedBy>SCGCA</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>SCGCA</Application><Company>Confidencial</Company>'
            . '</Properties>';
    }
}
