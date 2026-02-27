<?php
/*
    This services expects the URL of a file that resides in it's scope, appended with the a `.json`
    extension.
*/
#echo '<pre>'; var_dump($_GET); echo '</pre>'; exit;
if (empty($_GET['format'])) {
    die;
}

$format = $_GET['format'];
$formats = [
    'json'
];

if (!in_array($format, $formats)) {
    die;
}

$file = $_SERVER['DOCUMENT_ROOT'] . urldecode(str_replace('.' . $format, '', str_replace('?' . $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI'])));
#echo $file; exit;
if (!file_exists($file)) {
    die;
}

$pathinfo   = pathinfo($file);

$data = [];
$data['modified_time'] = filemtime($file);
$data['size']          = filesize($file);
$data['extension']     = $pathinfo['extension'];
$data['mime']          = mime_content_type($file);
$data['is_image']      = false;

if ($img_info = getimagesize($file)) {
    $data['is_image']       = true;
    $data['image_width']    = $img_info[0];
    $data['image_height']   = $img_info[1];
    $data['image_channels'] = $img_info[2];
    $data['image_dims']     = $img_info[3];
    $data['image_bits']     = $img_info['bits'];
    $data['image_mime']     = $img_info['mime'];
}

if ($format == 'json') {
    $data = json_encode($data, true);
    header('Content-type: application/json');
} else {
    $data = print_r($data);
    header('Content-type: text/plain');
}
echo $data;
exit;