<?php

class Google
{
    public static function analytics($analyticsID, $analyticsName)
    {
        $html = "<script>
            (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
             (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
             m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
             })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

        ga('create', '".$analyticsID."', 'auto');
        ga('send', 'pageview');

        </script>";

        return $html;
    }

    public static function getAd()
    {
        global $dataAdClient, $dataAdSlot;
return '
<div data-fuse="22070413988"></div>
';
    }
}
