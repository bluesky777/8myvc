<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromView;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\WithTitle;

class AcudientesSheet implements FromView, WithTitle
{
    private $grupo;
    private $acudientes;

    public function __construct($grupo, $acudientes)
    {
        $this->grupo = $grupo;
        $this->acudientes  = $acudientes;
    }

    /**
     * @return Builder
     */
    public function view(): View
    {
        return view('acudientes', [
            'acudientes' => $this->acudientes,
            'grupo' => $this->grupo,
        ]);
    }

    /**
     * @return string
     */
    public function title(): string
    {
        return $this->grupo->abrev;
    }
}