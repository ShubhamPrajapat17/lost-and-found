<?php
function findMax($a,$b,$c){
if ($a>=$b && $a>=$c){
return $a;
}elseif ($b>=$a && $b>=$c){
return $b;
} else{
return $c;
}
}
//Example usage
echo "Maximum number is:".findMax(10,25,15);
?>