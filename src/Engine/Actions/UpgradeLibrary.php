<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Library;
use FermeTranquille\Engine\Payload;

/**
 * payload: { item } — next library level of a product.
 */
final class UpgradeLibrary implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $library = new Library($farm->data);
        $item = $payload->string('item');
        $levels = $library->config()['items'][$item]['levels']
            ?? throw new GameError('unknown_item', 'Ce produit ne s\'améliore pas à la bibliothèque.', ['item' => $item]);

        $buildingLevel = $library->buildingLevel($farm);
        if ($buildingLevel === 0) {
            throw new GameError('no_library', 'Construisez d\'abord la bibliothèque.');
        }
        $level = $library->level($farm, $item);
        $next = $levels[$level] ?? throw new GameError('max_level', 'Niveau maximum atteint.');
        if ($next['level'] > $buildingLevel) {
            throw new GameError('library_too_low', "Améliorez la bibliothèque au niveau {$next['level']} d'abord.", [
                'needed' => $next['level'],
            ]);
        }
        $farm->assertLevel($farm->data->item($item)['unlock_level']);
        $farm->pay($next['cost']);

        $farm->setLibraryLevel($item, $level + 1);
    }
}
