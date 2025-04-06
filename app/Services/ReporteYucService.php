<?php

namespace App\Services;

use Mpdf\Mpdf;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Facades\View;

class ReporteYucService
{
    public function generateReportYucSitio(array $data): string
    {
        // Puedes reemplazar esto por tu vista real (ajustar en el paso 4)
        $html = View::make('pdf.reporte', ['data' => $data])->render();

        $pdf = new Mpdf();
        $pdf->WriteHTML($html);

        $filePath = storage_path('app/public/reporte-generado.pdf');
        $pdf->Output($filePath, 'F'); // Guardar en archivo

        return $filePath;
    }
}
