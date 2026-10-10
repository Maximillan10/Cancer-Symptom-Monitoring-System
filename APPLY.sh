#!/bin/sh
# Run from the project root (next to artisan):  sh APPLY.sh
set -e
if ! grep -q "EditorAccess::sharedView" app/Models/Alert.php; then
    sed -i 's/        if (\$user->isAdmin()) {/        if ($user->isAdmin() || \\App\\Support\\EditorAccess::sharedView()) {/' app/Models/Alert.php
fi
grep -n "sharedView" app/Models/Alert.php || echo "!! Alert.php was not changed, see README"
if [ -f phpunit.xml ] && ! grep -q EDITOR_EMAILS phpunit.xml; then
    sed -i '/<env name="APP_ENV"/a\        <env name="EDITOR_EMAILS" value=""/>' phpunit.xml
fi
php artisan view:clear
php artisan test --filter=SharedViewTest
