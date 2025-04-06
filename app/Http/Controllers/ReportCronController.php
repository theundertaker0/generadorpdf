<?php

namespace App\Http\Controllers;

//use App\Models\ConsolidateReport;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;
use Symfony\Component\Console\Output\ConsoleOutput;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class ReportCronController extends Job
{
    const DOMAIN = 'http://10.10.10.105:5000/';
    const REPORT_API = [
        'YUC' => self::DOMAIN . 'get_reporte_mensual',
        'QRO' => self::DOMAIN . 'get_reporte_mensual_queretaro',
        'YUC_SITIO' => 'http://100.104.188.99:5000/get_reporte_mensual_V2'
    ];
    const TIME_INTERVAL = 10;
    const MEGABYTE = 1024;
    const GIGABYTE = 1024 * ReportCronController::MEGABYTE;
    const TERABYTE = 1024 * ReportCronController::GIGABYTE;

    protected $user;
    protected $region;

    public function __construct()
    {
        // Inicializa el usuario autenticado y su región
        $this->user = auth()->user();
        $this->region = $this->user ? $this->user->regions()->first() : null;
    }

    public function __invoke()
    {
        $this->generateReport();
        $this->generateReport_qro();
        $this->generateReportYucSitio();
    }
    public function generateReportYucSitio()
    {
        $output = new ConsoleOutput();
        try {
            $data = $this->getDataYuc_Sitio();
            if (empty($data)) {
                $output->writeln("\033[31m[ERROR]\033[0m No se recibieron datos de Yucatán v2.\n");
                Log::error('No se recibió del API Yucatán v2');
                return;
            }
            $report = $this->getReportYuc_Sitio($data);
            $output->writeln("\033[32m[INFO]\033[0m Proceso completado exitosamente.\n");
            Log::info('Yucatán v2 report generated.');
        } catch (\Exception $e) {
            $output->writeln("\033[31m[ERROR]\033[0m Falló la generación del reporte de Yucatán v2.\n");
            Log::error('Failed to generate Yucatán v2 report');
            Log::error($e);
        }
    }
    private function getDataYuc_Sitio()
    {
        $client = new Client();
        $output = new ConsoleOutput();
        try {
            $response = $client->request('GET', ReportCronController::REPORT_API['YUC_SITIO']);
            if ($response->getStatusCode() === 200) {
                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);

                if (!isset($data['result'])) {
                    $output->writeln("\033[31m[ERROR]\033[0m No se encontraron datos en la respuesta.\n");
                    return response()->json(['error' => 'No data found in the response.'], 400);
                }

                $output->writeln("\033[32m[INFO]\033[0m Recuperados " . count($data['result']) . " resultados.\n");

                $usage = array_map(function ($element) {
                    return [
                        'cve_est' => $element['cve_est'],
                        'municipio' => $element['municipio'],
                        'localidad' => $element['localidad'],
                        'nombre' => $element['nombre'],
                        'consumo_descarga' => $element['consumo_descarga'],
                        'consumo_subida' => $element['consumo_subida'],
                        'total_consumo' => $element['total_consumo'],
                        'total_servicios' => $element['total_servicios'],
                    ];
                }, $data['result']);

                $usage_array['month'] = $data['month'];
                $usage_array['year'] = $data['year'];
                $usage_array['usage'] = $usage;

                return $usage_array;
            }
        } catch (\Exception $e) {
            $output->writeln("\033[31m[ERROR]\033[0m {$e->getMessage()}\n");
            Log::error($e->getMessage());
        }
    }
    private function getReportYuc_Sitio($request)
    {
        $output = new ConsoleOutput();
        $output->writeln("\033[32m[INFO]\033[0m Procesando solicitud...\n");

        if (empty($request) || !is_array($request)) {
            $output->writeln("\033[31m[ERROR]\033[0m El arreglo de solicitud está vacío en getReportYuc_Sitio.\n");
            return Log::info('Request array is empty in ReportCronController.php');
        }

        $data = $request['usage'];
        $month = $request['month'];
        $year = $request['year'];
        $data = $this->trafficByMunicipalityYuc_Sitio($data);

        $output->writeln("\033[32m[INFO]\033[0m Preparando estilo del documento \033[34m\033[0m");

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Ajustar anchos de columna
        $sheet->getColumnDimension('A')->setAutoSize(true);  // Columna No.
        $sheet->getColumnDimension('B')->setAutoSize(true);  // Columna No.
        $sheet->getColumnDimension('C')->setAutoSize(true);  // Columna No.
        // $sheet->getColumnDimension('D')->setAutoSize(true);  // Columna No.
        // $sheet->getColumnDimension('E')->setAutoSize(true); // Ajuste para la penúltima columna
        // $sheet->getColumnDimension('F')->setAutoSize(true); // Ajuste para la penúltima columna
        $sheet->getColumnDimension('E')->setWidth(15); // Ajuste para la penúltima columna
        $sheet->getColumnDimension('F')->setWidth(10); // Ajuste para la penúltima columna
        $sheet->getColumnDimension('G')->setAutoSize(true); // Última columna
        $sheet->getColumnDimension('H')->setAutoSize(true); // Última columna
        $sheet->getColumnDimension('I')->setAutoSize(true); // Última columna

        // Formato de la última columna (Ejemplo: Alineación y formato de número si aplica)
        // $sheet->getStyle('G:G')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        // $sheet->getStyle('G:G')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        // $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setSize(10);
        $spreadsheet->getActiveSheet()->getPageMargins()->setTop(0.25);
        $spreadsheet->getActiveSheet()->getPageMargins()->setRight(0.25);
        $spreadsheet->getActiveSheet()->getPageMargins()->setLeft(0.25);
        $spreadsheet->getActiveSheet()->getPageMargins()->setBottom(0.75);

        $spreadsheet->getProperties()
            ->setCreator('Burma Technologies')
            ->setLastModifiedBy('Burma Technologies')
            ->setTitle('Reporte mensual x municipio')
            ->setSubject('Reporte mensual x municipio')
            ->setDescription('Reporte mensual x municipio')
            ->setKeywords('Consolidate - Municipality')
            ->setCategory('Services');

        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 10,
                'name'  => 'Arial'
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => '5E2129']
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'wrapText' => false,
                'indent' => true,
                'shrinkToFit' => false
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => 'BABABA']
                ]
            ],
        ];

        $bodyStyle = [
            'font' => [
                'bold' => false,
                // 'color' => ['rgb' => 'FFFFFF'],
                'size'  => 10,
                'name'  => 'Arial Rounded'
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => 'f2f5f8']
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'wrapText' => false,
                'indent' => true,
                'shrinkToFit' => false
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '#FFFFFF']
                ],
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '#FFFFFF']
                ],
                'left' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '#FFFFFF']
                ],
                'right' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '#FFFFFF']
                ],
            ],
        ];

        // Estilos de los textos en el encabezado
        $titleStyle = [
            'font' => [
                'bold' => true,
                'size' => 15,
                'name' => 'Calibri'
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER
            ]
        ];

        $subtitleStyle = [
            'font' => [
                'bold' => false,
                'size' => 13,
                'name' => 'Calibri'
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER
            ]
        ];

        $spreadsheet->setActiveSheetIndex(0);
        $sheet = $spreadsheet->getActiveSheet();

        // Aplicar fondo blanco a toda la hoja
        $sheet->getStyle("A1:I118")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => 'FFFFFF']
            ]
        ]);

        $output->writeln("\033[32m[INFO]\033[0m Agregando logos \033[34m\033[0m");
        $drawing1 = new Drawing();
        $drawing1->setName('Yucatán');
        $drawing1->setDescription('Yucatán');
        $drawing1->setPath(realpath(public_path('img/logos/yucatan_2024_2030.png')));
        $drawing1->setHeight(90);
        $drawing1->setCoordinates('H1'); // Adjusted to align with the last column
        $drawing1->setOffsetX(0);
        $drawing1->setOffsetY(0);
        $drawing1->setWorksheet($sheet);

        $drawing2 = new Drawing();
        $drawing2->setName('QuattroCom');
        $drawing2->setDescription('QuattroCom');
        $drawing2->setPath(public_path('img/logos/quattrocom.png'));
        $drawing2->setHeight(90);
        $drawing2->setCoordinates('A1');
        $drawing2->setOffsetX(40);
        $drawing2->setOffsetY(10);
        $drawing2->setWorksheet($sheet);

        $output->writeln("\033[32m[INFO]\033[0m Configurando celdas \033[34m\033[0m");
        $sheet->mergeCells('A6:I6');
        $sheet->setCellValue('A6', 'REPORTE CONSOLIDADO DEL CONSUMO DE INTERNET POR SITIO');
        $sheet->getStyle('A6')->applyFromArray($titleStyle);

        $sheet->mergeCells('A7:I7');
        $sheet->setCellValue('A7', 'CONTRATO SAF/SARH/0082-00/2024');
        $sheet->getStyle('A7')->applyFromArray($subtitleStyle);

        $sheet->mergeCells('A8:I8');
        $sheet->setCellValue('A8', "{$this->getMonthName($month)} {$year}");
        $sheet->getStyle('A8')->applyFromArray($subtitleStyle);

        $sheet->getStyle("A13:I13")->applyFromArray($headerStyle);

        $headerRow = 14;
        $lastRow = $headerRow + count($data);

        for ($row = $headerRow; $row <= $lastRow; $row++) {
            for ($col = 'A'; $col <= 'I'; $col++) {
                $sheet->getStyle("{$col}{$row}")->applyFromArray($bodyStyle);
            }
        }

        // Apply styles to "TOTAL CONSUMO (GB)" column
        $sheet->getStyle("I14:I{$lastRow}")->applyFromArray([
            'numberFormat' => [
                'formatCode' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            ],
        ]);

        $headers = [
            'No',
            'CLAVE RED ESTATAL',
            'MUNICIPIO',
            'LOCALIDAD',
            'NOMBRE',
            'TOTAL DE SERVICIOS',
            'CONSUMO DESCARGA (GB)',
            'CONSUMO SUBIDA (GB)',
            'TOTAL CONSUMO (GB)'
        ];
        $sheet->fromArray($headers, NULL, 'A13');
        $sheet->fromArray($data, NULL, 'A14');

        foreach (range('A', 'I') as $columnID) {
            if ($columnID == 'A') {
                $sheet->getColumnDimension($columnID)->setWidth(0.5); // Further reduce width of "No" column
                continue;
            }
            if ($columnID == 'B') {
                $sheet->getColumnDimension($columnID)->setWidth(20);
                continue;
            }
            if ($columnID == 'I') {
                $sheet->getColumnDimension($columnID)->setWidth(8); // Reduce width of "CONSUMO SUBIDA (GB)" column
                continue;
            }
            $sheet->getColumnDimension($columnID)->setWidth(10); // Default width for other columns
        }

        $totalsRow = $lastRow + 1;
        // $sheet->setCellValue("B{$totalsRow}", 'TOTALES');
        // $sheet->setCellValue("F{$totalsRow}", "=SUM(F14:F{$lastRow})");
        // $sheet->setCellValue("G{$totalsRow}", "=SUM(G14:G{$lastRow})");
        // $sheet->setCellValue("H{$totalsRow}", "=SUM(H14:H{$lastRow})");
        // $sheet->setCellValue("I{$totalsRow}", "=SUM(I14:I{$lastRow})");

        // $sheet->getStyle("A{$totalsRow}:I{$totalsRow}")->applyFromArray([
        //     'font' => [
        //         'bold' => true,
        //         'color' => ['rgb' => '000000'],
        //         'size'  => 10,
        //         'name'  => 'Arial'
        //     ]
        // ]);

        $sheet->setTitle('Reporte mensual x sitio');

        $response = new StreamedResponse(function () use ($spreadsheet, $month, $year) {});

        // Set landscape orientation
        $spreadsheet->getActiveSheet()->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

        $spreadsheet->getActiveSheet()->getPageMargins()->setTop(1);
        $spreadsheet->getActiveSheet()->getPageMargins()->setBottom(1);
        $spreadsheet->getActiveSheet()->getPageMargins()->setLeft(1);
        $spreadsheet->getActiveSheet()->getPageMargins()->setRight(1);

        $spreadsheet->getActiveSheet()->getPageSetup()->setHorizontalCentered(true);
        $spreadsheet->getActiveSheet()->getPageSetup()->setVerticalCentered(true);

        $output->writeln("\033[32m[INFO]\033[0m Escribiendo estilo de la hoja \033[34m\033[0m");
        $writer = new Mpdf($spreadsheet);
        $this->storeReportYuc_Sitio($writer, $month, $year);
        $writer->save('php://output');
        $filename = 'archivo_' . time() . '.pdf';
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment;filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');
        return $response;
    }
    private function trafficByMunicipalityYuc_Sitio(array $markers): array
    {
        $output = new ConsoleOutput();
        $output->writeln("\033[32m[INFO]\033[0m Formateando trafico \033[34m\033[0m");
        $municipalities = [];

        foreach ($markers as $index => $marker) {
            $municipalities[] = [
                'No' => $index + 1,
                'CLAVE RED ESTATAL' => $marker['cve_est'] ?? 'N/A',
                'MUNICIPIO' => $marker['municipio'],
                'LOCALIDAD' => $marker['localidad'],
                'NOMBRE' => $marker['nombre'],
                'TOTAL DE SERVICIOS' => $marker['total_servicios'] ?? 0,
                'CONSUMO DESCARGA (GB)' => $this->getTrafficInGigabytes($marker['consumo_descarga'] ?? 0),
                'CONSUMO SUBIDA (GB)' => $this->getTrafficInGigabytes($marker['consumo_subida'] ?? 0),
                'TOTAL CONSUMO (GB)' => $this->getTrafficInGigabytes($marker['total_consumo'] ?? 0),
            ];
        }

        return $municipalities;
    }

    private function getTrafficInGigabytes($traffic)
    {
        return round($traffic / pow(1024, 2), 2);
    }

    private function getMonthName(int $month): string
    {
        $months = ["ENERO", "FEBRERO", "MARZO", "ABRIL", "MAYO", "JUNIO", "JULIO", "AGOSTO", "SEPTIEMBRE", "OCTUBRE", "NOVIEMBRE", "DICIEMBRE"];
        return $months[$month - 1];
    }
    private function storeReportYuc_Sitio($report, $month, $year)
    {
        $path = 'consolidate_reports/YUC_SITIO/' . $this->getMonthName($month) . '-' . $year . '.pdf';
        $filePath = storage_path('app/' . $path);

        if (!Storage::disk('local')->exists(dirname($path))) {
            Storage::disk('local')->makeDirectory(dirname($path));
        }

        $output = new ConsoleOutput();
        $output->writeln("\033[32m[INFO]\033[0m Guardando reporte en: \033[34m{$filePath}\033[0m");

        try {
            $report->save($filePath);

            if (Storage::disk('local')->exists($path)) {
                $output->writeln("\033[32m[INFO]\033[0m Reporte guardado exitosamente.\n");
            } else {
                $output->writeln("\033[31m[ERROR]\033[0m Archivo de reporte no encontrado después de guardar.\n");
            }
        } catch (\Exception $e) {
            $output->writeln("\033[31m[ERROR]\033[0m Falló al guardar el reporte: {$e->getMessage()}\n");
        }
    }

    public function getJobId()
    {
        // TODO: Implement getJobId() method.
    }

    public function getRawBody()
    {
        // TODO: Implement getRawBody() method.
    }
}
