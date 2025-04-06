<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\ReportCronController;
use Illuminate\Support\Facades\Storage;

class GenerarReporteYuc extends Command
{
    protected $signature = 'report:yucsitio-json';
    protected $description = 'Generar PDF desde un archivo JSON con el mismo formato que generateReportYucSitio() espera';

    public function handle()
    {
        // Ruta del archivo JSON (ajusta si está en otro lado)
        $jsonPath = storage_path('app/datosenero.json');

        if (!file_exists($jsonPath)) {
            $this->error('El archivo datos.json no se encontró en storage/app/');
            return 1;
        }

        // Leer y decodificar el archivo
        $jsonContent = file_get_contents($jsonPath);
        $data = json_decode($jsonContent, true);

        if (!$data) {
            $this->error('No se pudo decodificar el JSON. Verifica el formato.');
            return 1;
        }

        // Instanciar el controlador
        $controller = new ReportCronController();

        // Llamar directamente al método con los datos
        $controller->generateReportYucSitio($data);

        $this->info('PDF generado correctamente desde JSON.');
        return 0;
    }
}
