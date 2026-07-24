<?php
// appel des controllers 
$fichiers = scandir("./controllers");

for ($i = 2; $i < count($fichiers); $i++) {
    require "controllers/" . $fichiers[$i];
}

if (isset($_GET['c']) and isset($_GET['a'])) {
    $controller = $_GET['c'];
    $action = $_GET['a'];

    if (class_exists($controller) and method_exists($controller, $action)) {

        $cont = new $controller();
        $cont->$action();
    } else {
        echo "404";
    }
}
