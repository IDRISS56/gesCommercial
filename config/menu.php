<?php
  
    if(!empty($_SESSION['role'])){  
        if($_SESSION['role'] == "Superviseur") {  
        include "config/menu/superviseur.php";  
    } } 
    ?>

    <?php

    if(!empty($_SESSION['role'])){  
        if($_SESSION['role'] == "Administrateur") {  
        include "config/menu/administrateur.php";   
    } } 
    ?>


    <?php  
    if(!empty($_SESSION['role'])){  
        if($_SESSION['role'] == "Assistant") {  
        include "config/menu/assistant.php";   
    } } 
    ?>



    <?php  
    if(!empty($_SESSION['role'])){  
        if($_SESSION['role'] == "Caisse") {  
        include "config/menu/caisse.php";  
    } } 
    ?>




<?php
if(!empty($_SESSION['role'])){ 
      if($_SESSION['role'] != "Administrateur" and $_SESSION['role'] != "Assistant" and $_SESSION['role'] != "Superviseur" and  $_SESSION['role'] != "Caisse") {
                    session_destroy();
                    ?>
    <script type='text/javascript'>document.location.replace('<?php if(substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].dirname($_SERVER["PHP_SELF"])),-1) =="/"){ echo (substr(((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].dirname($_SERVER["PHP_SELF"])), 0,-1)); }else{ echo ((isset($_SERVER["HTTPS"]) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].dirname($_SERVER["PHP_SELF"]));} ?>/utilisateur/deconnexion');</script>";
        <?php
                exit();
                } }
                ?>