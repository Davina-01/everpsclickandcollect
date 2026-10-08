<?php
// Replays what AdminCarrierWizardController does when the staff edits a carrier
// (PS 8.2 controllers/admin/AdminCarrierWizardController.php l.795-823; same in 9.x):
// duplicate the carrier, flag the old one deleted, fire actionCarrierUpdate.
require __DIR__ . '/_boot.php';
$current = new Carrier((int) $argv[1]);
$new = $current->duplicateObject();
$current->deleted = true;
$current->update();
$new->position = $current->position;
$new->update();
Hook::exec('actionCarrierUpdate', array('id_carrier' => (int) $current->id, 'carrier' => $new));
echo json_encode(array('old' => (int) $current->id, 'new' => (int) $new->id)), "\n";
