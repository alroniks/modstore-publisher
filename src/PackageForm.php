<?php

declare(strict_types = 1);

namespace Alroniks\Publisher;

use Spatie\DataTransferObject\Attributes\Strict;
use Spatie\DataTransferObject\DataTransferObject;

#[Strict]
final class PackageForm extends DataTransferObject
{
    // validator?
    public string $action;

    // validator?
    public int $package_id;



    public function multipart(): array
    {
        $form = [
            'action' => 'office/versions/create', // or update? office/versions/update
            // id version
            'package_id' => 9,
            'changelog' => '',
            'changelog_en' => '',
            'minimum_supports' => '2.8',
            'supports' => '',
            'minimum_php' => '',
            'deprecate_other' => '',
            'package' => '' // psr stream // Psr7\Utils::tryFopen(__DIR__ . '/*.transport.zip', 'r')
        ];

//        [
//            'name'     => 'action',
//            'contents' => 'office/versions/create'
//        ],
    }

}
