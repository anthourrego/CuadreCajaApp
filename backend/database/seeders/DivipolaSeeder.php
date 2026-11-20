<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DivipolaSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/divipola-municipios-2024.csv');
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException("No se pudo abrir el archivo DIVIPOLA: {$path}");
        }

        $departments = [];
        $municipalities = [];
        try {
            $header = fgetcsv($file, 0, ',', '"', '');
            if ($header === false) {
                throw new RuntimeException('El archivo DIVIPOLA está vacío.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $columns = array_flip($header);
            foreach (['cod_dpto', 'dpto', 'cod_mpio', 'nom_mpio', 'tipo_municipio'] as $required) {
                if (! array_key_exists($required, $columns)) {
                    throw new RuntimeException("Falta la columna DIVIPOLA: {$required}");
                }
            }

            while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
                if (count($row) !== count($header)) {
                    throw new RuntimeException('El archivo DIVIPOLA contiene una fila incompleta.');
                }
                $departmentCode = trim($row[$columns['cod_dpto']]);
                $departmentName = trim($row[$columns['dpto']]);
                $code = trim($row[$columns['cod_mpio']]);
                $name = trim($row[$columns['nom_mpio']]);
                $type = trim($row[$columns['tipo_municipio']]);

                if (! preg_match('/^\d{2}$/', $departmentCode) || ! preg_match('/^\d{5}$/', $code)
                    || ! str_starts_with($code, $departmentCode) || $departmentName === '' || $name === '' || $type === ''
                    || isset($municipalities[$code])
                    || (isset($departments[$departmentCode]) && $departments[$departmentCode]['name'] !== $departmentName)) {
                    throw new RuntimeException("Registro DIVIPOLA inválido o duplicado: {$code}");
                }

                $departments[$departmentCode] = ['code' => $departmentCode, 'name' => $departmentName];
                $municipalities[$code] = ['code' => $code, 'department_code' => $departmentCode, 'name' => $name, 'type' => $type];
            }
        } finally {
            fclose($file);
        }

        if (count($departments) !== 33 || count($municipalities) !== 1122) {
            throw new RuntimeException('El archivo DIVIPOLA no contiene todos los registros del corte 2024.');
        }

        DB::transaction(function () use ($departments, $municipalities) {
            DB::table('departments')->upsert(array_values($departments), ['code'], ['code']);
            foreach (array_chunk(array_values($municipalities), 200) as $chunk) {
                DB::table('municipalities')->upsert($chunk, ['code'], ['code']);
            }
        });
    }
}
