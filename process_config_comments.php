<?php
function file_force_contents($filename, $data, $flags = 0)
{
    if (!is_dir(dirname($filename))) {
        mkdir(dirname($filename).'/', 0777, true);
    }
    return file_put_contents($filename, $data, $flags);
}

function removeKeysFromAssociativeArray($associativeArray, $keysToUnset)
{
    if (empty($associativeArray) || empty($keysToUnset)) {
        return array();
    }

    foreach ($associativeArray as $key => $arr) {
        if (!is_array($arr)) {
            continue;
        }

        foreach ($keysToUnset as $keyToUnset) {
            if (array_key_exists($keyToUnset, $associativeArray)) {
                unset($associativeArray[$keyToUnset]);
            }
        }
        $associativeArray[$key] = $arr;
    }
    return $associativeArray;
}

$sModulesPath = "../modules/";
$sModulesCopyPath = "../modules_configs/";

$aModulesList = array_diff(scandir($sModulesPath), array('..', '.'));
// Modules list for debug
// $aModulesList = ["Core"];
// $aModulesList = glob('./modules_configs/*', GLOB_ONLYDIR);

$contentHtml = "";
$mainHtml = "";

foreach ($aModulesList as $sModuleName) {
    if (!is_dir($sModulesPath . $sModuleName)) {
        continue;
    }

    echo "Processing " . $sModuleName . " module\n";

    $sFileContent = file_get_contents($sModulesPath . $sModuleName . "/config.json");

    $aParametersList = json_decode($sFileContent, true);

    $aNewParametersList = [];
    $bCommentAdded = false;

    foreach ($aParametersList as $sParamName => $aParamValue) {
        switch (true) {
            //both parameter and comment are exist
            case (substr($sParamName, -12) === '_Description' && isset($aParametersList[substr($sParamName, 0, -12)])):
                $aNewParametersList[substr($sParamName, 0, -12)] = $aParametersList[substr($sParamName, 0, -12)];
                $aNewParametersList[$sParamName] = $aParamValue;
                break;
                //got a param without a comment
            case (substr($sParamName, -12) !== '_Description'):
                $sParamCommentName = $sParamName.'_Description';
                $bCommentExists = isset($aParametersList[$sParamCommentName]);

                if (!$bCommentExists) {
                    $aNewParametersList[$sParamName] = $aParamValue;
                    $aNewParametersList[$sParamCommentName] = ["", "string"];
                    $bCommentAdded = true;
                }
                break;
                //removing orphan comment
            case (substr($sParamName, -12) === '_Description'):
                // $bParamExists = isset($aParametersList[substr($sParamName, 0, -8)]);
                break;
        }
    }

    if ($bCommentAdded) {
        echo "Modified " . $sModuleName . " module's config\n";
    }

    // ksort($aParametersList);

    $output = json_encode($aNewParametersList, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    //make 2 spaces indents instead of 4 spaces
    $output = preg_replace('/^(  +?)\\1(?=[^ ])/m', '$1', $output);
    //make tabs indents instead of spaces
    $output = preg_replace('/  /', "\t", $output);

    $result = file_put_contents($sModulesPath . $sModuleName . "/config.json", $output);

    $sConfigCopyName = $sModulesCopyPath . $sModuleName . "/config" . ($bCommentAdded ? "_modifiled" : "") . ".json";
    $result = file_force_contents($sConfigCopyName, $output);

    //generating HTML
    $sFileContent = file_get_contents($sModulesPath . $sModuleName . "/config.json");

    $mainHtml .= "<a name=\"".$sModuleName."\"></a>\n";
    $mainHtml .= "<h2>".$sModuleName."</h2>\n";
    $mainHtml .= "<dl>\n";

    $contentHtml .= "\t<li><a href=\"#".$sModuleName."\">".$sModuleName."</a></li>\n";

    $aParametersList = json_decode($sFileContent, true);
    $aNewParametersList = [
        'Disabled' => $aParametersList['Disabled'],
        'Disabled_Description' => $aParametersList['Disabled_Description']
    ];
    $aParametersList = $aNewParametersList + removeKeysFromAssociativeArray($aParametersList, ['Disabled', 'Disabled_Description']);

    foreach ($aParametersList as $sParamName => $aParamValue) {
        if (substr($sParamName, -12) === '_Description') {
            $mainHtml .= "\t<dt>".substr($sParamName, 0, -12)."</dt>\n";
            $mainHtml .= "\t<dd>".$aParamValue[0]."</dd>\n";
        }
    }
    $mainHtml .= "</dl>\n";
}

$outputHtml = "<html><head>\n";
$outputHtml .= "<style>\n";
$outputHtml .= "body {font-family: Arial, sans-serif;}\n";
$outputHtml .= "h2, dt {color: #222; margin: 1em 0 .5em; font: bold 24pt/1.2 Titillium Web,sans-serif; }\n";
$outputHtml .= "dt {font-size: 16pt;}\n";
$outputHtml .= "dd { margin: 0 0 1em; color: #444; font: 12pt/1.9 Open Sans,sans-serif; }\n";
$outputHtml .= "</style>\n";
$outputHtml .= "<link href=\".//afterlogic.com/media/mod_documentation_tree/jstree/themes/default/style.min.css\" rel=\"stylesheet\">\n";
$outputHtml .= "</head><body>\n";

$outputHtml .= "<ul class=\"content\" style=\"column-count: 3\">\n" . $contentHtml . "</ul>\n";
$outputHtml .= $mainHtml;

$outputHtml .= "</body></html>";

$result = file_force_contents($sModulesCopyPath . "configs.html", $outputHtml);
