<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Seeder;

class EmployeeCompanySeeder extends Seeder
{
    /**
     * Asigna los empleados (por biotime_id) a su Razón Social (por RFC).
     */
    public function run(): void
    {
        $assignmentsByRfc = [
            // GASOLINAS Y SERVICIOS DEL NORTE (CIMA)
            'GSN0309051J3' => [
                '1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008',
                '1009', '1010', '1011', '1012', '1013', '1014', '1016',
            ],
            // COMBUSTIBLES MARGA (12 DE MARZO Y TAMAULIPAS)
            'CMA0601196M9' => [
                '1501', '1503', '1504', '1506', '1507', '1508', '1509', '1510',
                '1511', '1512', '1513', '1514', '1516', '1517', '1518', '1519',
                '1520', '1521',
            ],
            // GASOLINAS Y LUBRICANTES
            'GLU9512074R2' => [
                '1', '2', '3', '4', '5', '6', '7', '9', '10', '11', '12', '13',
                '14', '15', '16', '17', '18', '19', '20', '21', '22', '23', '25',
                '26', '27', '28', '29', '30', '31', '32', '33', '34', '35', '36',
                '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '49',
                '50', '51', '52', '53', '54', '55', '56', '57', '60',
            ],
            // DIESEL Y GASOLINAS DEL NORTE
            'DGN980310PU2' => [
                '3001', '3002', '3003', '3004', '3005', '3006', '3007', '3008',
                '3009', '3010', '3011', '3012', '3014', '3015', '3016', '3017',
                '3018', '3019', '3020', '3021', '3022', '3023', '3024', '3025',
                '3026', '3027', '3028', '3029', '3030', '3031', '3032', '3033',
                '3034', '3035', '3036', '3037', '3038', '3039', '3040', '3041',
                '3042', '3043', '3044', '3045', '3046',
            ],
            // INMOBILIARIA Y DESARROLLADORA DEL NORTE
            'IDN021017HV2' => [
                '2414', '2531', '2740', '2774', '2775',
            ],
            // INGENIERIA ESPECIALIZADA Y ASOCIADOS
            'IEA8910039C1' => [
                '1720', '1893', '2276', '2290', '2387', '2396', '2425', '2478',
                '2493', '2669',
            ],
        ];

        $totalUpdated = 0;

        foreach ($assignmentsByRfc as $rfc => $biotimeIds) {
            $company = Company::where('rfc', $rfc)->first();

            if (! $company) {
                $this->command?->warn("Empresa no encontrada para RFC {$rfc}. Ejecuta primero CompanySeeder.");
                continue;
            }

            $updated = Employee::whereIn('biotime_id', $biotimeIds)
                ->update(['company_id' => $company->id]);

            $totalUpdated += $updated;
            $this->command?->info("{$rfc} ({$company->business_name}): {$updated} empleado(s) asignado(s).");
        }

        $this->command?->info("Total de asignaciones aplicadas: {$totalUpdated} empleado(s).");
    }
}
