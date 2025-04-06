<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reporte</title>
    <style>
        body { font-family: sans-serif; }
    </style>
</head>
<body>
<h1>Reporte desde JSON</h1>
<p><strong>Nombre del sitio:</strong> {{ $data['sitio']['nombre'] ?? 'No definido' }}</p>
<p><strong>Ubicación:</strong> {{ $data['sitio']['ubicacion'] ?? 'No definida' }}</p>

{{-- Aquí puedes mapear más campos según tu estructura --}}
</body>
</html>
