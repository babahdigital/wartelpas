<?php
/*
 *		Copyright (C) 2018 Laksamadi Guko.
 *
 *		This program is free software; you can redistribute it and/or modify
 *		it under the terms of the GNU General Public License as published by
 *		the Free Software Foundation; either version 2 of the License, or
 *		(at your option) any later version.
 *
 *		This program is distributed in the hope that it will be useful,
 *		but WITHOUT ANY WARRANTY; without even the implied warranty of
 *		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.		See the
 *		GNU General Public License for more details.
 *
 *		You should have received a copy of the GNU General Public License
 *		along with this program.		If not, see <http://www.gnu.org/licenses/>.
 */
session_start();
 // hide all error
error_reporting(0);
?>
<!DOCTYPE html>
<html>
	<head>
		<title>Wartelpas </title>
		<meta charset="utf-8">
		<meta http-equiv="cache-control" content="private" />
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<!-- Tell the browser to be responsive to screen width -->
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<!-- Theme color -->
		<meta name="theme-color" content="<?= $themecolor ?>" />
		<!-- Font Awesome -->
		<link rel="stylesheet" type="text/css" href="css/font-awesome/css/font-awesome.min.css" />
		<!-- Mikhmon UI -->
		<link rel="stylesheet" href="css/mikhmon-ui.<?= $theme; ?>.min.css">
		<!-- favicon -->
		<link rel="icon" href="./img/favicon.png" />
		<!-- jQuery -->
		<script src="js/jquery.min.js"></script>
		<!-- pace -->
		<link href="css/pace.<?= $theme; ?>.css" rel="stylesheet" />
		<link href="css/login.css" rel="stylesheet" />
		<script src="js/pace.min.js"></script>

		<style>
			.global-tooltip {
				position: fixed;
				z-index: 99999;
				background: rgba(17, 24, 39, 0.95);
				color: #f9fafb;
				padding: 6px 10px;
				border-radius: 6px;
				font-size: 12px;
				line-height: 1.3;
				box-shadow: 0 6px 16px rgba(0, 0, 0, 0.35);
				pointer-events: none;
				max-width: 260px;
				word-wrap: break-word;
				opacity: 0;
				transition: opacity 0.08s ease;
			}
		</style>
		<script>
			(function() {
				var tooltipEl = null;
				var offsetX = 14;
				var offsetY = 18;

				function ensureTooltip() {
					if (tooltipEl) return tooltipEl;
					tooltipEl = document.createElement('div');
					tooltipEl.className = 'global-tooltip';
					document.body.appendChild(tooltipEl);
					return tooltipEl;
				}

				function setPosition(x, y) {
					if (!tooltipEl) return;
					var maxX = window.innerWidth - tooltipEl.offsetWidth - 8;
					var maxY = window.innerHeight - tooltipEl.offsetHeight - 8;
					var posX = Math.min(x + offsetX, maxX);
					var posY = Math.min(y + offsetY, maxY);
					tooltipEl.style.left = posX + 'px';
					tooltipEl.style.top = posY + 'px';
				}

				document.addEventListener('mouseover', function(e) {
					var target = e.target;
					if (!target || !target.getAttribute) return;
					var title = target.getAttribute('title');
					if (!title) return;
					target.setAttribute('data-title', title);
					target.removeAttribute('title');
					var el = ensureTooltip();
					el.textContent = title;
					el.style.opacity = '1';
					setPosition(e.clientX, e.clientY);
				}, true);

				document.addEventListener('mousemove', function(e) {
					if (!tooltipEl || tooltipEl.style.opacity !== '1') return;
					setPosition(e.clientX, e.clientY);
				});

				document.addEventListener('mouseout', function(e) {
					var target = e.target;
					if (!target || !target.getAttribute) return;
					var dataTitle = target.getAttribute('data-title');
					if (dataTitle !== null) {
						target.setAttribute('title', dataTitle);
						target.removeAttribute('data-title');
					}
					if (tooltipEl) tooltipEl.style.opacity = '0';
				}, true);
			})();
		</script>

		
	</head>
	<body>
		<div class="wrapper">

			
