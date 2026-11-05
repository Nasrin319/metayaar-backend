<?php

use App\Support\ArticleHtml;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Articles written before the rich text editor are plain text; keep their paragraphs, headings and
     * lists as HTML. There is no way back: the editor's formatting can't be stored as plain text.
     */
    public function up(): void
    {
        DB::table('articles')->orderBy('id')->each(function (object $article): void {
            DB::table('articles')->where('id', $article->id)->update(['body' => ArticleHtml::fromPlainText($article->body)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
