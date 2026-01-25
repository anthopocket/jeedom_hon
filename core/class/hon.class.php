private function createSelectionWorkflowCommands() {
    // Infos
    $this->createInfoCommand('selectedProgram',     'Programme sélectionné', 'string');
    $this->createInfoCommand('selectedProgramInfo', 'Infos du programme',    'string');

    // Boutons
    $this->createActionCommand('start_selected',  'Démarrer le programme sélectionné');
    $this->createActionCommand('clear_selection', 'Effacer la sélection');

    // Type d’appareil
    $applianceType = $this->getConfiguration('applianceType', '');
    $applianceCode = self::getApplianceTypeCode($applianceType);

    // Température uniquement pour WM/WD
    if (in_array($applianceCode, ['WM', 'WD'])) {
        $this->createInfoCommand('desired_temp', 'Température choisie', 'numeric', '°C');

        $values = [0, 20, 30, 40, 60, 90];
        $pairs  = array_map(function($v){ return $v . '|' . $v; }, $values);
        $listValue = implode(';', $pairs);

        $setTemp = $this->getCmd(null, 'set_temp');
        if (!is_object($setTemp)) {
            $this->createSelectActionCommand('set_temp', 'Régler la température', $values);
        } else {
            $setTemp->setType('action');
            $setTemp->setSubType('select');
            $setTemp->setIsVisible(1);
            $setTemp->setName('Régler la température');
            $setTemp->setConfiguration('listValue', $listValue);
            $setTemp->save();
        }
    }
}
