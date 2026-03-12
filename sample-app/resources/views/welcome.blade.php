<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Counter App</title>
        <link rel="stylesheet" href="/css/app.css">
        <script
            src="https://code.jquery.com/jquery-3.7.0.min.js"
            integrity="sha256-2Pmvv0kuTBOenSvLm6bvfBSSHrUJ+3A7x6P5Ebd07/g="
            crossorigin="anonymous"></script>
    </head>
    <body>
        <div class="container">
            <h1>Sample App</h1>
            <p class="counter-label">Counter</p>
            <p id="value">{{ $value }}</p>
            <button id="add">+1</button>
        </div>

        <script>
            $(document).ready(function(){
                $("#add").click(function(e){
                    $.get("/api/counter/add", function(data){
                        var $val = $('#value');
                        $val.text(data.value);
                        $val.addClass('bump');
                        setTimeout(function(){ $val.removeClass('bump'); }, 200);
                    });
                });
            });
        </script>
    </body>
</html>
