<?php

declare(strict_types = 1);

namespace Alroniks\Publisher;

use JetBrains\PhpStorm\Deprecated;
use Spatie\DataTransferObject\Attributes\Strict;
use Spatie\DataTransferObject\DataTransferObject;

#[Strict]
#[Deprecated]
final class PackageForm extends DataTransferObject
{
    // validator?
    public string $action;

    // validator?
    public int $package_id;

    public string $changelog;

    public string $chagelog_en;

    public string $deprecate_other;

    public string $package;


    public function multipart(): array
    {
        $output = [];

        foreach ($this->except('package') as $property => $value) {
            $output[] = [
                'name' => $property,
                'contents' => $value
            ];
        }

        $output[] = [
            'name' => 'package',
            'contents' => Psr7\Utils::tryFopen(__DIR__ . '/*.transport.zip', 'r')
        ];

        $form = [
            'action' => 'office/versions/create', // or update? office/versions/update
            // id version
            'package_id' => 9,
            'changelog' => '',
            'changelog_en' => '',
            'minimum_supports' => '2.8',
            'supports' => '',
            'minimum_php' => '',
            'deprecate_other' => '', // только есть добавлять новую
            'package' => '' // psr stream // Psr7\Utils::tryFopen(__DIR__ . '/*.transport.zip', 'r')
        ];

        //            deprecated
//            deprecate_other

        return $output;
    }

}
