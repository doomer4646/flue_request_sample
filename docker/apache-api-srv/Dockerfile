FROM php:7.0-apache

ADD /docker/apache-api-srv/001-default.conf /etc/apache2/sites-available
RUN ln -s /etc/apache2/sites-available/001-default.conf /etc/apache2/sites-enabled/001-default.conf