<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHabitRequest extends FormRequest {
  public function authorize(): bool { return true; }
  public function rules(): array {
    return [
      'name' => 'required|string|max:120',
      'frequency' => 'required|in:daily,weekly',
      'target_per_day' => 'nullable|integer|min:1|max:50',
      'reminder_time' => 'nullable|date_format:H:i',
    ];
  }
}
