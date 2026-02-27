# plg_imageservice
Independent image service plugin similar to plg_assets.


## Important

You need to add thie following code to your /htaccess file ABOVE the default Joomla stuff after installation:

```
# ----------------------------------------------------------------------
# Single Entry Point for plg_imageservice
#
# IMPORTANT: This file is part of plg_assets and will be overwritten
# whenever that extension is updated, so DO NOT MAKE CHANGES HERE.
# ----------------------------------------------------------------------
<IfModule mod_rewrite.c>
    Options +FollowSymlinks
    RewriteEngine On
    RewriteBase /

    # File info requests are appended with .json
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(.+)\.(png|jpg|gif)\.(json)$ /plugins/system/imageservice/services/fileinfo/fileinfo.php?format=$3 [NC,L]

    # Redirect requests for images:
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteCond %{QUERY_STRING} s=
    RewriteRule ^(.+)\.(png|jpg|gif)$ /plugins/system/imageservice/services/images/images.php [NC,L]

</IfModule>
```