<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

/**
 * Assembles an ODS spreadsheet. Callers supply the `<table:table>` blocks —
 * everything around them (zip container, manifest, styles, meta) is identical
 * for every export this app produces.
 *
 * @psalm-type OdsCell = scalar|null|array{value?: scalar|null, type?: string, style?: string}
 */
class OdsWriter {
	public const STYLE_CELL = 'ce1';
	public const STYLE_HEADER = 'ce2';
	public const STYLE_YES = 'ce-yes';
	public const STYLE_NO = 'ce-no';
	public const STYLE_MAYBE = 'ce-maybe';
	public const STYLE_SECTION = 'ce-section';

	/**
	 * @param string $tablesXml One or more `<table:table>` elements
	 * @throws \Exception When the file cannot be assembled
	 */
	public function write(string $tablesXml): string {
		if (!class_exists('\ZipArchive')) {
			throw new \Exception('ZipArchive extension is not available. Please install php-zip extension.');
		}

		$tempFile = tempnam(sys_get_temp_dir(), 'ods_');
		if ($tempFile === false) {
			throw new \Exception('Failed to create temporary file');
		}

		$zip = new \ZipArchive();
		$result = $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		if ($result !== true) {
			throw new \Exception('Failed to create ODS file. ZipArchive error code: ' . (int)$result);
		}

		// Must be first and uncompressed for readers to recognise the format.
		$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
		$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);

		$zip->addFromString('META-INF/manifest.xml', $this->manifestXml());
		$zip->addFromString('content.xml', $this->contentXml($tablesXml));
		$zip->addFromString('styles.xml', $this->stylesXml());
		$zip->addFromString('meta.xml', $this->metaXml());

		$zip->close();

		$content = file_get_contents($tempFile);
		if ($content === false) {
			throw new \Exception('Failed to read generated ODS file');
		}

		unlink($tempFile);

		return $content;
	}

	/**
	 * Render a sheet from plain values.
	 *
	 * A cell is either a scalar (rendered as text) or
	 * `['value' => scalar, 'type' => 'string'|'float'|'percentage', 'style' => string]`.
	 *
	 * @param list<list<OdsCell>> $rows
	 */
	public function renderTable(string $name, array $rows): string {
		$xml = '
			<table:table table:name="' . $this->escape($name) . '" table:print="false">';

		foreach ($rows as $row) {
			$xml .= '
				<table:table-row>';
			foreach ($row as $cell) {
				$xml .= $this->renderCell($cell);
			}
			$xml .= '
				</table:table-row>';
		}

		return $xml . '
			</table:table>';
	}

	public function escape(string $text): string {
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	/**
	 * @param OdsCell $cell
	 */
	private function renderCell(mixed $cell): string {
		$spec = is_array($cell) ? $cell : ['value' => $cell];
		$value = $spec['value'] ?? '';
		$type = $spec['type'] ?? 'string';
		$style = $spec['style'] ?? self::STYLE_CELL;

		if ($value === '') {
			return '
					<table:table-cell table:style-name="' . $style . '"/>';
		}

		if ($type === 'float' || $type === 'percentage') {
			$numeric = (float)$value;
			$display = $type === 'percentage'
				? number_format($numeric * 100.0, 1) . ' %'
				: (string)$numeric;
			return '
					<table:table-cell table:style-name="' . $style . '" office:value-type="' . $type
				. '" office:value="' . (string)$numeric . '">
						<text:p>' . $this->escape($display) . '</text:p>
					</table:table-cell>';
		}

		return '
					<table:table-cell table:style-name="' . $style . '" office:value-type="string">
						<text:p>' . $this->escape((string)$value) . '</text:p>
					</table:table-cell>';
	}

	private function contentXml(string $tablesXml): string {
		return '<?xml version="1.0" encoding="UTF-8"?>
<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
	xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
	xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
	xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
	xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
	xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"
	office:version="1.2">
	<office:font-face-decls>
		<style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;" style:font-family-generic="swiss" style:font-pitch="variable"/>
	</office:font-face-decls>
	<office:automatic-styles>
		<style:style style:name="co1" style:family="table-column">
			<style:table-column-properties style:column-width="3cm"/>
		</style:style>
		<style:style style:name="co2" style:family="table-column">
			<style:table-column-properties style:column-width="1.7cm"/>
		</style:style>
		<style:style style:name="' . self::STYLE_CELL . '" style:family="table-cell">
			<style:table-cell-properties fo:border="0.05pt solid #000000"/>
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt"/>
		</style:style>
		<style:style style:name="' . self::STYLE_HEADER . '" style:family="table-cell">
			<style:table-cell-properties fo:border="0.05pt solid #000000" fo:background-color="#e0e0e0"/>
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt" fo:font-weight="bold"/>
		</style:style>
		<style:style style:name="' . self::STYLE_SECTION . '" style:family="table-cell">
			<style:table-cell-properties fo:border="0.05pt solid #000000" fo:background-color="#f2f2f2"/>
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt" fo:font-weight="bold"/>
		</style:style>
		<style:style style:name="' . self::STYLE_YES . '" style:family="table-cell">
			<style:table-cell-properties fo:border="0.05pt solid #000000" fo:background-color="#c6efce"/>
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt"/>
		</style:style>
		<style:style style:name="' . self::STYLE_NO . '" style:family="table-cell">
			<style:table-cell-properties fo:border="0.05pt solid #000000" fo:background-color="#ffc7ce"/>
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt"/>
		</style:style>
		<style:style style:name="' . self::STYLE_MAYBE . '" style:family="table-cell">
			<style:table-cell-properties fo:border="0.05pt solid #000000" fo:background-color="#ffeb9c"/>
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt"/>
		</style:style>
	</office:automatic-styles>
	<office:body>
		<office:spreadsheet>' . $tablesXml . '
		</office:spreadsheet>
	</office:body>
</office:document-content>';
	}

	private function manifestXml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>
<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">
	<manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>
	<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
	<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>
	<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>
</manifest:manifest>';
	}

	private function metaXml(): string {
		$date = date('Y-m-d\TH:i:s');
		return '<?xml version="1.0" encoding="UTF-8"?>
<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
	xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	office:version="1.2">
	<office:meta>
		<meta:generator>Nextcloud Attendance App</meta:generator>
		<dc:title>Attendance Export</dc:title>
		<dc:date>' . $date . '</dc:date>
	</office:meta>
</office:document-meta>';
	}

	private function stylesXml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>
<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
	xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
	xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
	xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"
	xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
	office:version="1.2">
	<office:font-face-decls>
		<style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;" style:font-family-generic="swiss" style:font-pitch="variable"/>
	</office:font-face-decls>
	<office:styles>
		<style:default-style style:family="table-cell">
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt"/>
		</style:default-style>
	</office:styles>
</office:document-styles>';
	}
}
