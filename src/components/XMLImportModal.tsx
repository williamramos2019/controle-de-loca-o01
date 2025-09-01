
import React, { useState } from 'react';
import { XMLData } from '../types';
import Modal from './Modal';

interface XMLImportModalProps {
  isOpen: boolean;
  onClose: () => void;
  onImport: (xmlData: XMLData) => void;
}

export default function XMLImportModal({ isOpen, onClose, onImport }: XMLImportModalProps) {
  const [xmlFile, setXmlFile] = useState<File | null>(null);
  const [xmlData, setXmlData] = useState<XMLData | null>(null);
  const [loading, setLoading] = useState(false);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      setXmlFile(file);
      setXmlData(null);
    }
  };

  const processXML = async () => {
    if (!xmlFile) return;

    setLoading(true);
    try {
      const text = await xmlFile.text();
      const parser = new DOMParser();
      const xml = parser.parseFromString(text, 'text/xml');

      // Verificar se é NFe válida
      const nfe = xml.querySelector('NFe');
      if (!nfe) {
        throw new Error('Arquivo não é uma NF-e válida.');
      }

      const infNFe = nfe.querySelector('infNFe');
      const emit = infNFe?.querySelector('emit');
      const ide = infNFe?.querySelector('ide');
      const total = infNFe?.querySelector('total ICMSTot');

      if (!emit || !ide) {
        throw new Error('Dados da NF-e incompletos.');
      }

      // Extrair dados da empresa
      const company = {
        cnpj: emit.querySelector('CNPJ')?.textContent || '',
        name: emit.querySelector('xNome')?.textContent || '',
        phone: emit.querySelector('enderEmit fone')?.textContent || '',
        email: emit.querySelector('email')?.textContent || '',
        address: [
          emit.querySelector('enderEmit xLgr')?.textContent,
          emit.querySelector('enderEmit nro')?.textContent,
          emit.querySelector('enderEmit xBairro')?.textContent,
          emit.querySelector('enderEmit xMun')?.textContent,
          emit.querySelector('enderEmit UF')?.textContent
        ].filter(Boolean).join(', ')
      };

      // Extrair dados da movimentação
      const movement = {
        nfe: ide.querySelector('nNF')?.textContent || '',
        date: ide.querySelector('dhEmi')?.textContent?.substring(0, 10) || '',
        total_value: parseFloat(total?.querySelector('vNF')?.textContent || '0')
      };

      // Extrair produtos
      const products: any[] = [];
      const detElements = infNFe.querySelectorAll('det');
      
      detElements.forEach(det => {
        const prod = det.querySelector('prod');
        if (prod) {
          const quantity = parseFloat(prod.querySelector('qCom')?.textContent || '0');
          const price = parseFloat(prod.querySelector('vUnCom')?.textContent || '0');
          
          products.push({
            name: prod.querySelector('xProd')?.textContent || '',
            code: prod.querySelector('cProd')?.textContent || '',
            unit: prod.querySelector('uCom')?.textContent || 'UN',
            quantity,
            price,
            total: quantity * price
          });
        }
      });

      const processedData: XMLData = {
        company,
        movement,
        products
      };

      setXmlData(processedData);
    } catch (error) {
      alert('Erro ao processar XML: ' + (error as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const handleImport = () => {
    if (xmlData) {
      onImport(xmlData);
      setXmlFile(null);
      setXmlData(null);
    }
  };

  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL'
    }).format(value);
  };

  const formatCnpj = (cnpj: string) => {
    const cleanCnpj = cnpj.replace(/\D/g, '');
    if (cleanCnpj.length === 14) {
      return cleanCnpj.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    }
    return cnpj;
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Importar XML de NF-e"
      size="4xl"
    >
      <div className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            Arquivo XML da NF-e
          </label>
          <input
            type="file"
            accept=".xml"
            onChange={handleFileChange}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
          />
        </div>

        {xmlData && (
          <div className="bg-gray-50 p-4 rounded-lg">
            <h4 className="text-md font-medium text-gray-700 mb-2">Dados Encontrados:</h4>
            <div className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-blue-50 rounded-lg">
                <div>
                  <h5 className="font-semibold text-gray-700 mb-2">📋 Empresa:</h5>
                  <p className="font-medium">{xmlData.company.name}</p>
                  <p className="text-sm text-gray-600">CNPJ: {formatCnpj(xmlData.company.cnpj)}</p>
                  {xmlData.company.phone && <p className="text-sm text-gray-600">Tel: {xmlData.company.phone}</p>}
                  {xmlData.company.email && <p className="text-sm text-gray-600">Email: {xmlData.company.email}</p>}
                </div>
                <div>
                  <h5 className="font-semibold text-gray-700 mb-2">📄 NF-e:</h5>
                  <p className="font-medium">Número: {xmlData.movement.nfe}</p>
                  <p className="text-sm text-gray-600">Data: {new Date(xmlData.movement.date).toLocaleDateString('pt-BR')}</p>
                  <p className="text-lg font-bold text-green-600">Total: {formatCurrency(xmlData.movement.total_value)}</p>
                </div>
              </div>

              <div className="p-4 bg-green-50 rounded-lg">
                <h5 className="font-semibold text-gray-700 mb-3">📦 Produtos da Nota ({xmlData.products.length} itens)</h5>
                <div className="max-h-64 overflow-y-auto space-y-1 border rounded-lg">
                  {xmlData.products.map((product, index) => (
                    <div key={index} className={`flex items-center justify-between py-2 px-3 ${index % 2 === 0 ? 'bg-gray-50' : 'bg-white'} rounded`}>
                      <div className="flex-1">
                        <div className="font-medium text-sm text-gray-900">{product.name}</div>
                        {product.code && <div className="text-xs text-gray-500">Código: {product.code}</div>}
                        {product.unit && <div className="text-xs text-gray-500">Unidade: {product.unit}</div>}
                      </div>
                      <div className="text-right">
                        <div className="text-sm font-medium text-gray-900">Qtd: {product.quantity}</div>
                        <div className="text-sm text-gray-600">Unit: {formatCurrency(product.price)}</div>
                        <div className="text-sm font-semibold text-blue-600">Total: {formatCurrency(product.total)}</div>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        )}

        <div className="flex justify-end space-x-2">
          <button
            onClick={onClose}
            className="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300"
          >
            Cancelar
          </button>
          <button
            onClick={processXML}
            disabled={!xmlFile || loading}
            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-400"
          >
            <i className="fas fa-upload mr-2"></i>
            {loading ? 'Processando...' : 'Processar XML'}
          </button>
          {xmlData && (
            <button
              onClick={handleImport}
              className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
            >
              <i className="fas fa-save mr-2"></i>Salvar Movimentação
            </button>
          )}
        </div>
      </div>
    </Modal>
  );
}
