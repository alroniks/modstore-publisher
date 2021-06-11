<?php
/**
 * This file is part of modstore publisher package.
 * For complete copyright and license information, see the LICENSE file
 * found in the top-level directory of this distribution.
 **/

declare(strict_types = 1);

namespace Alroniks\Publisher;

use Alroniks\Publisher\Exceptions\ExtraException;
use Alroniks\Publisher\Exceptions\TokenException;
use DiDom\Document;
use JetBrains\PhpStorm\ArrayShape;

class ContentParser
{
    private string $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function token(): string
    {
        // todo: replace by DiDom parser?
        $tempFile = tmpfile();
        fwrite($tempFile, $this->content);
        $metatags = get_meta_tags(stream_get_meta_data($tempFile)['uri']);

        if (!array_key_exists('csrf-token', $metatags)) {
            throw new TokenException('CSRF token not found, next operations impossible.');
        }

        return $metatags['csrf-token'];
    }

    #[ArrayShape(['id' => "int", 'versions' => "array"])]
    public function extra(): array
    {
        $document = new Document($this->content);

        $input = $document->first('#office-package-form input[name="id"]');

        if (!$input) {
            throw new ExtraException('Element with extra ID is not found on the page.');
        }

        $versions = [];
        foreach ($document->find('#pdopage tr.version') as $version) {
            $cells = $version->find('td');

            $href = $cells[4]->first('a.fa-edit')->getAttribute('href');
            $segments = explode('/', $href);

            $versions[] = [
                'id' => (int) array_pop($segments),
                'active' => !str_contains($version->getAttribute('class'), 'inactive'),
                'version' => trim($cells[0]->text()),
                'released' => trim($cells[1]->text()),
                'downloads' => (int) trim($cells[2]->text()),
                'updated' => trim($cells[3]->text())
            ];
        }

        return [
            'id' => (int) $input->getAttribute('value'),
            'versions' => $versions
        ];
    }
}
