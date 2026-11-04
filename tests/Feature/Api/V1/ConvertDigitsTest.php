<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ConvertDigitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/digits-test', fn (Request $request) => $request->all());
    }

    public function test_it_converts_persian_and_arabic_digits_in_json_and_nested_input(): void
    {
        $this->postJson('/digits-test', [
            'mobile' => '۰۹۱۲۳۴۵۶۷۸۹',
            'code' => '٠١٢٣٤٥٦٧٨٩',
            'nested' => ['title' => 'بازی ۲ و 3'],
        ])->assertExactJson([
            'mobile' => '09123456789',
            'code' => '0123456789',
            'nested' => ['title' => 'بازی 2 و 3'],
        ]);
    }

    public function test_it_converts_form_and_query_input(): void
    {
        $this->post('/digits-test?page=۲', ['price' => '۱۵۰'])
            ->assertExactJson(['page' => '2', 'price' => '150']);
    }

    public function test_it_leaves_passwords_as_typed(): void
    {
        $passwords = [
            'current_password' => 'قدیمی۱۲۳',
            'password' => 'رمز۱۲۳٤',
            'password_confirmation' => 'رمز۱۲۳٤',
        ];

        $this->postJson('/digits-test', $passwords)->assertExactJson($passwords);
    }
}
