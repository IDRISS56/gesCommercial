<?php
// Certains fichiers de controllers/ sont enregistrés avec un BOM UTF-8 en
// tête. Comme ils sont tous inclus ci-dessous à chaque requête, ce BOM se
// retrouve au tout début de CHAQUE réponse — invisible dans un navigateur,
// mais fatal pour du JSON (dataType: 'json' de jQuery refuse tout ce qui ne
// commence pas par '{'). On le filtre une fois pour toutes ici plutôt que
// de corriger chaque fichier individuellement.
ob_start(function ($buffer) {
    return preg_replace('/^(\xEF\xBB\xBF)+/', '', $buffer);
});

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