<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Article;
use App\Support\ArticleHtml;
use App\Rules\PlainText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArticleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the slug before validating so the unique rule checks what will be stored.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('slug')) {
            $this->merge(['slug' => Article::slugify((string) $this->input('slug'))]);
        }

        // only what is safe to print reaches validation and the database; too long a body fails its max rule
        if (is_string($this->input('body')) && strlen($this->input('body')) <= ArticleHtml::MAX_LENGTH) {
            $this->merge(['body' => ArticleHtml::clean($this->input('body'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160', new PlainText],
            'slug' => ['nullable', 'string', 'max:180', Rule::unique('articles')->ignore($this->route('article'))],
            'excerpt' => ['nullable', 'string', 'max:300', new PlainText],
            'body' => [
                'required', 'string', 'max:'.ArticleHtml::MAX_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! ArticleHtml::hasContent($value)) {
                        $fail('متن مقاله را بنویسید.');
                    }
                },
            ],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=5000,max_height=5000'],
            'remove_image' => ['nullable', 'boolean'],
            'is_published' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'متن مقاله را بنویسید.',
            'body.max' => 'متن مقاله خیلی طولانی است.',
        ];
    }
}
