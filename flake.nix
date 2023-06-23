{
  description = "Dev environment";

  outputs = { self, nixpkgs }:
    let pkgs = nixpkgs.legacyPackages.x86_64-linux;
    in {
      defaultPackage.x86_64-linux =
        pkgs.mkShell {
          buildInputs =  with pkgs; [
            (php82.withExtensions
              ({ all, ... }: with all; [
                bz2
                curl
                dom
                filter
                fileinfo
                gd
                iconv
                imagick
                intl
                mbstring
                openssl
                pdo
                pdo_mysql
                pdo_sqlite
                session
                sodium
                sqlite3
                tidy
                tokenizer
                xdebug
                xmlwriter
#                yaml
                zip
                zlib
              ])
            )
            php82Packages.composer
            libjpeg
            libpng
            libyaml
        ]; };
    };
}
