<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>retroBITE</title>
    <?= \App\Helpers\Vite::assets(['resources/js/app.ts']) ?>
</head>
<body>
    <div id="app" data-page="<?= $pageJson ?>"></div>
</body>
</html>
