# See here for image contents: https://github.com/microsoft/vscode-dev-containers/tree/main/containers/php/.devcontainer/base.Dockerfile

# [Choice] PHP version: 8, 8.0, 7, 7.4, 7.3
ARG PHP_VERSION="8.0"
FROM mcr.microsoft.com/vscode/devcontainers/php:${PHP_VERSION}

# [Choice] Node.js version: none, lts/*, 16, 14, 12, 10
ARG NODE_VERSION="none"
RUN if [ "${NODE_VERSION}" != "none" ]; then su vscode -c "umask 0002 && . /usr/local/share/nvm/nvm.sh && nvm install ${NODE_VERSION} 2>&1"; fi

# Additional dependencies
RUN apt-get update && export DEBIAN_FRONTEND=noninteractive \
	&& apt-get -y install --no-install-recommends libzip-dev \
	&& docker-php-ext-install zip \
	&& docker-php-source delete \
	&& apt-get clean -y && rm -rf /var/lib/apt/lists/*

# [Optional] Uncomment this line to install global node packages.
# RUN su vscode -c "source /usr/local/share/nvm/nvm.sh && npm install -g <your-package-here>" 2>&1

# Set up Docker
COPY library-scripts/docker-in-docker-debian.sh /tmp/library-scripts/
RUN apt-get update && /bin/bash /tmp/library-scripts/docker-in-docker-debian.sh
ENTRYPOINT ["/usr/local/share/docker-init.sh"]
VOLUME [ "/var/lib/docker" ]
CMD ["sleep", "infinity"]