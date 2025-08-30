<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertHabitLogRequest extends FormRequest {
  public function authorize(): bool { return true; }
  public function rules(): array {
    return [
      'log_date' => 'required|date_format:Y-m-d',
      'count' => 'required|integer|min:0|max:200',
    ];
  }
}
