#!/bin/bash
PS_VERSION=$1

set -e

echo "Pull PrestaShop files (Tag ${PS_VERSION})"

docker rm -f temp-ps || true
docker volume rm -f ps-volume || true

docker run -tid --rm -v ps-volume:/var/www/html --name temp-ps prestashop/prestashop:$PS_VERSION

echo "Clear previous module"

docker exec -t temp-ps rm -rf /var/www/html/modules/ps_onepagecheckout

echo "Run PHPStan using phpstan-${PS_VERSION}.neon file"

docker run --rm --volumes-from temp-ps \
       -v $PWD:/var/www/html/modules/ps_onepagecheckout \
       -e _PS_ROOT_DIR_=/var/www/html \
       --workdir=/var/www/html/modules/ps_onepagecheckout phpstan/phpstan:0.12 \
       analyse \
       --configuration=/var/www/html/modules/ps_onepagecheckout/tests/php/phpstan/phpstan-$PS_VERSION.neon
