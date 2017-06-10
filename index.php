<?php
header('Access-Control-Allow-Origin: https://www.treasurydirect.gov');
$jquery_file = "bin/jquery.js?r=" . filemtime('bin/jquery.js');
$jquery_ui_file = "bin/jquery-ui/jquery-ui.min.js?r=" . filemtime('bin/jquery-ui/jquery-ui.min.js');
$jquery_css_file = "bin/jquery-ui/jquery-ui.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.min.css');
$str_css_file = "bin/jquery-ui/jquery-ui.structure.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.structure.min.css');
$theme_css_file = "bin/jquery-ui/jquery-ui.theme.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.theme.min.css');
$dot_url = 'https://www.treasurydirect.gov/NP_WS/debt/search';
?>
<html>
<head>
    <script src="<?php echo $jquery_file; ?>" type="text/javascript"></script>
    <script src="<?php echo $jquery_ui_file; ?>" type="text/javascript"></script>
    <link rel="stylesheet" href="<?php echo $jquery_css_file; ?>">
    <link rel="stylesheet" href="<?php echo $str_css_file; ?>">
    <link rel="stylesheet" href="<?php echo $theme_css_file; ?>">
    <script type="text/javascript">
        var usDebt = {
            url: "",
            url_params: [],
            setUrl: function(val) {
              this.url = val;
            },
            addUrlParam: function(val) {
                this.url_params[val] = "";
            },
            setParam: function(key, val) {
                this.url_params[key] = val;
                console.log('url_params');
                console.log(this.url_params);
            },
            getFormattedUrl: function() {
                var params = [];
                for (var key in this.url_params) {
                    if (this.url_params.hasOwnProperty(key)) {
                        params.push(key + "=" + this.url_params[key]);
                    }
                }
                var output = this.url + '?' + params.join('&');
                console.log('url ' + output);
                return output;
            },
            fetch: function () {
                var t = this.getFormattedUrl();
                $(document).ready(function() {
                    $.ajaxSetup({xhrFields: { withCredentials: true } });
                    $.get({
                        url: t,
                        jsonp: "callback",
                        success: function(result){
                            alert(result);
                        }
                    });
                });
            }
        }
    </script>
</head>
<body>
    <form id="search">
        <label for="start_date">Start Date:</label><input type="text" id="start_date" name="start_date" value="" />
        <label for="end_date">End Date:</label><input type="text" id="end_date" name="end_date" value="" />
        <input type="button" id="fetch" name="fetch" value="Fetch" />
    </form>
</body>
<footer>

</footer>
<script type="text/javascript">
    $(document).ready(function() {
        console.log('jquery loaded');
        /* this is the url parameter name which DOT is expecting */
        var $start_date = 'startdate';
        /* this is the url parameter name which DOT is expecting */
        var $end_date = 'enddate';

        /* bindings */
        $('#start_date').bind('change', function(){
            usDebt.setParam($start_date, $(this).val());
        }).datepicker({
            showOtherMonths: true,
            selectOtherMonths: true,
            changeMonth: true,
            changeYear: true,
            dateFormat: 'yy-mm-dd'
        });
        $('#end_date').bind('change', function(){
            usDebt.setParam($end_date, $(this).val());
        }).datepicker({
            showOtherMonths: true,
            selectOtherMonths: true,
            changeMonth: true,
            changeYear: true,
            dateFormat: 'yy-mm-dd'
        });
        $('#fetch').bind('click', function(){
            usDebt.fetch();
        });

        /* init usDebt */
        usDebt.setUrl('<?php echo $dot_url; ?>');
        usDebt.addUrlParam($start_date);
        usDebt.addUrlParam($end_date);
        console.log(usDebt);
    })
</script>
</html>
