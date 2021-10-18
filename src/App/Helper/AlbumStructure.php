<?php

namespace App\Helper;

use EMS\CommonBundle\Common\Standard\Json;

class AlbumStructure
{
    /** @var array<mixed> */
    private array $parents = [];
    /** @var array<mixed> */
    private array $structure = [];

    /**
     * @param array{Z_PK: int, ZTITLE: string, ZPARENTFOLDER: int, ZUUID: string} $row
     * @param string[]                                                            $assetsArray
     */
    public function addRow(array $row, array $assetsArray): void
    {
        $id = $row['Z_PK'];
        $data = [
            'id' => $row['ZUUID'],
            'label' => $row['ZTITLE'] ?? 'Albums',
            'type' => 'album',
            'object' => [
                'label' => $row['ZTITLE'] ?? 'Albums',
                'title' => $row['ZTITLE'] ?? 'Albums',
                'assets' => $assetsArray,
            ],
            'children' => [],
        ];
        $parentId = $row['ZPARENTFOLDER'];

        if (isset($this->parents[$parentId])) {
            $parent = &$this->parents[$parentId]['children'];
        } else {
            $parent = &$this->structure;
        }
        $parent[] = &$data;
        $this->parents[$id] = &$data;
    }

    public function getStructure(): string
    {
        return Json::encode($this->structure, true);
    }
}
