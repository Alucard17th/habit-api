<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHabitRequest extends FormRequest {
  public function authorize(): bool { return true; }
  public function rules(): array {
    return [
      'name' => 'sometimes|string|max:120',
      'frequency' => 'sometimes|in:daily,weekly',
      'target_per_day' => 'sometimes|integer|min:1|max:50',
      'reminder_time' => 'nullable|date_format:H:i',
      'is_archived' => 'sometimes|boolean',
    ];
  }
}
