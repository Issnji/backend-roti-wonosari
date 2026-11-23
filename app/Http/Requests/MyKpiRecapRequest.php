<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MyKpiRecapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // data selalu milik user login
    }

    public function rules(): array
    {
        return ['month' => ['nullable', 'date_format:Y-m']];
    }
}