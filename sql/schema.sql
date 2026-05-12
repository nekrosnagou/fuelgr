-- FuelGR Database Schema
-- Run this first, then import gasstations.sql, pricedata.sql, users.sql

CREATE DATABASE IF NOT EXISTS fuelgr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fuelgr;

-- Users table (gas station owners + consumers)
CREATE TABLE IF NOT EXISTS `users` (
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `role` ENUM('owner','consumer') NOT NULL DEFAULT 'consumer',
  PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gas stations table
CREATE TABLE IF NOT EXISTS `gasstations` (
  `gasStationID` INT(11) NOT NULL,
  `gasStationLat` DECIMAL(10,7) NOT NULL,
  `gasStationLong` DECIMAL(10,7) NOT NULL,
  `fuelCompID` INT(11) DEFAULT NULL,
  `fuelCompNormalName` VARCHAR(100) DEFAULT NULL,
  `gasStationOwner` VARCHAR(200) DEFAULT NULL,
  `ddID` VARCHAR(20) DEFAULT NULL,
  `ddNormalName` VARCHAR(100) DEFAULT NULL,
  `municipalityID` VARCHAR(20) DEFAULT NULL,
  `municipalityNormalName` VARCHAR(100) DEFAULT NULL,
  `countyID` VARCHAR(20) DEFAULT NULL,
  `countyName` VARCHAR(100) DEFAULT NULL,
  `gasStationAddress` VARCHAR(200) DEFAULT NULL,
  `phone1` VARCHAR(20) DEFAULT NULL,
  `username` VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (`gasStationID`),
  KEY `fk_station_user` (`username`),
  CONSTRAINT `fk_station_user` FOREIGN KEY (`username`) REFERENCES `users` (`username`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Price data table
CREATE TABLE IF NOT EXISTS `pricedata` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `gasStationID` INT(11) NOT NULL,
  `fuelTypeID` INT(11) NOT NULL,
  `fuelSubTypeID` INT(11) NOT NULL DEFAULT 0,
  `fuelNormalName` VARCHAR(100) NOT NULL,
  `fuelName` VARCHAR(200) NOT NULL,
  `fuelPrice` DECIMAL(6,3) NOT NULL,
  `dateUpdated` DATETIME DEFAULT NULL,
  `isPremium` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_station_fuel` (`gasStationID`,`fuelTypeID`,`fuelSubTypeID`),
  CONSTRAINT `fk_price_station` FOREIGN KEY (`gasStationID`) REFERENCES `gasstations` (`gasStationID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Orders table
CREATE TABLE IF NOT EXISTS `orders` (
  `orderID` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `gasStationID` INT(11) NOT NULL,
  `fuelTypeID` INT(11) NOT NULL,
  `fuelSubTypeID` INT(11) NOT NULL DEFAULT 0,
  `fuelName` VARCHAR(200) NOT NULL,
  `quantity` DECIMAL(8,2) NOT NULL,
  `pricePerLt` DECIMAL(6,3) NOT NULL,
  `totalCost` DECIMAL(10,2) NOT NULL,
  `orderDate` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `isExecuted` TINYINT(1) NOT NULL DEFAULT 0,
  `executedDate` DATETIME DEFAULT NULL,
  PRIMARY KEY (`orderID`),
  KEY `fk_order_user` (`username`),
  KEY `fk_order_station` (`gasStationID`),
  CONSTRAINT `fk_order_user` FOREIGN KEY (`username`) REFERENCES `users` (`username`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_station` FOREIGN KEY (`gasStationID`) REFERENCES `gasstations` (`gasStationID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add role column if importing existing users data (run after importing users.sql)
-- UPDATE users SET role='owner' WHERE username IN (SELECT DISTINCT username FROM gasstations);
