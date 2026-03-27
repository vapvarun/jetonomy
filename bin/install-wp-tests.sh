#!/usr/bin/env bash
# install-wp-tests.sh
#
# Installs the WordPress PHPUnit test library and a minimal WordPress install
# for running tests. Based on the official WP-CLI scaffold template:
# https://raw.githubusercontent.com/wp-cli/scaffold-command/master/templates/install-wp-tests.sh
#
# Usage:
#   bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# Example:
#   bash bin/install-wp-tests.sh wp_tests root root 127.0.0.1 6.9

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
    if [ $(which curl) ]; then
        curl -s "$1" > "$2"
    elif [ $(which wget) ]; then
        wget -nv -O "$2" "$1"
    fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
    WP_BRANCH=${WP_VERSION%\-*}
    WP_TESTS_TAG="branches/$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
    WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
    if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
        # Version x.y.0 doesn't have its own tag; use the branch instead.
        WP_TESTS_TAG="branches/${WP_VERSION%.*}"
    else
        WP_TESTS_TAG="tags/$WP_VERSION"
    fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
    WP_TESTS_TAG="trunk"
else
    # Version is 'latest' or an empty string.
    # Get the latest stable version from the WordPress.org API.
    download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
    grep '[0-9]+\.[0-9]+(\.[0-9]+)?' /tmp/wp-latest.json
    LATEST_VERSION=$(grep -o '"version":"[^"]*"' /tmp/wp-latest.json | head -1 | sed 's/"version":"//;s/"//')
    if [[ -z "$LATEST_VERSION" ]]; then
        echo "Latest WordPress version could not be found"
        exit 1
    fi
    WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

set -ex

install_wp() {
    if [ -d $WP_CORE_DIR ]; then
        return;
    fi

    mkdir -p $WP_CORE_DIR

    if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
        mkdir -p $TMPDIR/wordpress-nightly
        download https://wordpress.org/nightly-builds/wordpress-latest.zip $TMPDIR/wordpress-nightly/wordpress-nightly.zip
        unzip -q $TMPDIR/wordpress-nightly/wordpress-nightly.zip -d $TMPDIR/wordpress-nightly/
        mv $TMPDIR/wordpress-nightly/wordpress/* $WP_CORE_DIR
    else
        if [ $WP_VERSION == 'latest' ]; then
            local ARCHIVE_NAME='latest'
        elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
            # https://wordpress.org/wordpress-5.7.zip
            local ARCHIVE_NAME="wordpress-$WP_VERSION"
        fi
        download https://wordpress.org/${ARCHIVE_NAME}.zip $TMPDIR/wordpress.zip
        unzip -q $TMPDIR/wordpress.zip -d $TMPDIR/
        mv $TMPDIR/wordpress/* $WP_CORE_DIR
    fi

    download https://raw.github.com/markoheijnen/wp-mysqli/master/db.php $WP_CORE_DIR/wp-content/db.php
}

install_test_suite() {
    # Portable in-place argument for both GNU and BSD sed.
    if [[ $(uname -s) == 'Darwin' ]]; then
        local ioption='-i.bak'
    else
        local ioption='-i'
    fi

    # Set up testing suite if it doesn't yet exist.
    if [ -d $WP_TESTS_DIR ]; then
        return;
    fi

    # Set up testing suite.
    mkdir -p $WP_TESTS_DIR
    svn co --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ $WP_TESTS_DIR/includes
    svn co --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ $WP_TESTS_DIR/data

    if [ -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
        return;
    fi

    download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
    # Remove all forward slashes in the end.
    WP_CORE_DIR=$(echo $WP_CORE_DIR | sed "s:/\+$::")
    sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
    sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
    sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
    sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
    sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
}

install_db() {
    if [ ${SKIP_DB_CREATE} = "true" ]; then
        return
    fi

    # Parse DB_HOST for port or socket references.
    local PARTS=(${DB_HOST//\:/ })
    local DB_HOSTNAME=${PARTS[0]};
    local DB_SOCK_OR_PORT=${PARTS[1]};
    local EXTRA=""

    if ! [ -z $DB_SOCK_OR_PORT ] ; then
        if [ $(echo $DB_SOCK_OR_PORT | grep -e '^[0-9]\{1,\}$') ]; then
            EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
        elif ! [ -z $DB_SOCK_OR_PORT ] ; then
            EXTRA=" --socket=$DB_SOCK_OR_PORT"
        fi
    elif ! [ -z $DB_HOSTNAME ] ; then
        EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
    fi

    # Create the database and grant privileges.
    mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS"$EXTRA
    mysql --user="$DB_USER" --password="$DB_PASS"$EXTRA -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'%'"
}

install_wp
install_test_suite
install_db
