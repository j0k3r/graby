{
  description = "Dev environment";

  outputs = { self, nixpkgs }:
    let pkgs = nixpkgs.legacyPackages.x86_64-linux;
    in {
      defaultPackage.x86_64-linux =
        pkgs.mkShell {
          buildInputs =  with pkgs; [
            (php82.withExtensions
              ({ enabled, all }: with all; enabled ++ [
                tidy
              ])
            )
            php82Packages.composer
        ]; };
    };
}
