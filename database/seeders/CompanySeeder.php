<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Seed the 6 companies / legal employers (razones sociales).
     */
    public function run(): void
    {
        $companies = [
            [
                'rfc' => 'IEA8910039C1',
                'business_name' => 'INGENIERIA ESPECIALIZADA Y ASOCIADOS, SA DE CV',
                'imss_employer_registration' => null,
                'workplace_address' => null,
                'device_identifier' => 'Reloj Biométrico BioTime Pro · TimeXis',
                'notes' => null,
                'active' => true,
            ],
            [
                'rfc' => 'GLU9512074R2',
                'business_name' => 'GASOLINAS Y LUBRICANTES, S.A. DE C.V.',
                'imss_employer_registration' => null,
                'workplace_address' => null,
                'device_identifier' => 'Reloj Biométrico BioTime Pro · TimeXis',
                'notes' => null,
                'active' => true,
            ],
            [
                'rfc' => 'GSN0309051J3',
                'business_name' => 'GASOLINAS Y SERVICIOS DEL NORTE, SA DE CV',
                'imss_employer_registration' => null,
                'workplace_address' => null,
                'device_identifier' => 'Reloj Biométrico BioTime Pro · TimeXis',
                'notes' => null,
                'active' => true,
            ],
            [
                'rfc' => 'DGN980310PU2',
                'business_name' => 'DIESEL Y GASOLINAS DEL NORTE, S.A. DE C.V.',
                'imss_employer_registration' => null,
                'workplace_address' => null,
                'device_identifier' => 'Reloj Biométrico BioTime Pro · TimeXis',
                'notes' => null,
                'active' => true,
            ],
            [
                'rfc' => 'IDN021017HV2',
                'business_name' => 'INMOBILIARIA Y DESARROLLADORA DEL NORTE, SA DE CV',
                'imss_employer_registration' => null,
                'workplace_address' => null,
                'device_identifier' => 'Reloj Biométrico BioTime Pro · TimeXis',
                'notes' => null,
                'active' => true,
            ],
            [
                'rfc' => 'CMA0601196M9',
                'business_name' => 'COMBUSTIBLES MARGA S.A. DE C.V.',
                'imss_employer_registration' => null,
                'workplace_address' => null,
                'device_identifier' => 'Reloj Biométrico BioTime Pro · TimeXis',
                'notes' => null,
                'active' => true,
            ],
        ];

        foreach ($companies as $data) {
            Company::firstOrCreate(
                ['rfc' => $data['rfc']],
                $data
            );
        }
    }
}
