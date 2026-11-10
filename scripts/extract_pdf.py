"""
Extrae texto de un PDF usando PyMuPDF + Tesseract OCR.
- PDFs nativos: extracción directa (rápido, perfecto)
- PDFs escaneados: renderiza cada página y aplica OCR con Tesseract

Uso: python extract_pdf.py <ruta_pdf>
Salida: texto plano por stdout (UTF-8)
"""
import sys
import fitz           # PyMuPDF
import pytesseract
from PIL import Image
import io

# Ruta de Tesseract en Windows
pytesseract.pytesseract.tesseract_cmd = r'C:\Program Files\Tesseract-OCR\tesseract.exe'

# Idiomas: inglés + español (suficiente para POs industriales)
LANGS = 'eng+spa'


def es_legible(texto: str) -> bool:
    """El texto tiene suficiente contenido real (no basura de fuentes sin mapeado)."""
    texto = texto.strip()
    if len(texto) < 40:
        return False
    letras = sum(1 for c in texto if c.isalpha())
    return (letras / len(texto)) >= 0.35


def pagina_a_imagen(page, dpi: int = 250) -> Image.Image:
    """Renderiza una página PDF a imagen PIL para OCR."""
    mat = fitz.Matrix(dpi / 72, dpi / 72)
    pix = page.get_pixmap(matrix=mat, colorspace=fitz.csGRAY)   # escala de grises → mejor OCR
    return Image.open(io.BytesIO(pix.tobytes("png")))


def extraer_texto(ruta: str) -> str:
    doc   = fitz.open(ruta)
    paginas_texto = []

    for i, page in enumerate(doc, start=1):
        # 1. Intentar texto nativo primero (gratis, instantáneo)
        texto_nativo = page.get_text("text").strip()
        if es_legible(texto_nativo):
            paginas_texto.append(texto_nativo)
        else:
            # 2. Página escaneada → OCR con Tesseract
            img   = pagina_a_imagen(page)
            texto = pytesseract.image_to_string(img, lang=LANGS,
                        config='--psm 3')   # PSM 3 = detección automática de layout
            paginas_texto.append(texto.strip())

    doc.close()
    return "\n\n".join(paginas_texto)


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit(1)

    try:
        resultado = extraer_texto(sys.argv[1])
        if es_legible(resultado):
            sys.stdout.buffer.write(resultado.encode("utf-8"))
        # Si no hay texto legible (PDF completamente corrupto), no imprime nada
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)
