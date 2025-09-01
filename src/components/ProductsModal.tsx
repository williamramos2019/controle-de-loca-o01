
import React from 'react';
import { Movement, Company } from '../types';
import Modal from './Modal';

interface ProductsModalProps {
  isOpen: boolean;
  onClose: () => void;
  movement: Movement;
  company?: Company;
}

export default function ProductsModal({ isOpen, onClose, movement, company }: ProductsModalProps) {
  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL'
    }).format(value);
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString + 'T00:00:00').toLocaleDateString('pt-BR');
  };

  const formatCnpj = (cnpj?: string) => {
    if (!cnpj) return '';
    const cleanCnpj = cnpj.replace(/\D/g, '');
    if (cleanCnpj.length === 14) {
      return cleanCnpj.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    }
    return cnpj;
  };

  const typeLabels = {
    'entrada': 'Entrada',
    'saida': 'Saída',
    'devolucao': 'Devolução'
  };

  const totalQuantity = movement.products.reduce((sum, p) => sum + p.quantity, 0);
  const totalValue = movement.products.reduce((sum, p) => sum + p.totalValue, 0);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`Produtos da Movimentação - ${movement.nfe || 'S/N'}`}
      size="5xl"
    >
      <div className="space-y-4">
        <div className="bg-blue-50 p-4 rounded-lg">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <h5 className="font-semibold text-gray-700 mb-1">📋 Empresa:</h5>
              <p className="font-medium">{company?.name || 'N/A'}</p>
              {company?.cnpj && <p className="text-sm text-gray-600">CNPJ: {formatCnpj(company.cnpj)}</p>}
            </div>
            <div>
              <h5 className="font-semibold text-gray-700 mb-1">📄 Movimentação:</h5>
              <p className="font-medium">Tipo: {typeLabels[movement.type]}</p>
              <p className="text-sm text-gray-600">Data: {formatDate(movement.date)}</p>
              {movement.nfe && <p className="text-sm text-gray-600">NF-e: {movement.nfe}</p>}
            </div>
            <div>
              <h5 className="font-semibold text-gray-700 mb-1">💰 Valor:</h5>
              <p className="text-xl font-bold text-green-600">{formatCurrency(movement.total_value)}</p>
              {movement.notes && <p className="text-sm text-gray-600">{movement.notes}</p>}
            </div>
          </div>
        </div>

        <div className="table-container max-h-64 overflow-y-auto">
          {movement.products.length === 0 ? (
            <div className="p-6 text-center text-gray-500">
              <i className="fas fa-box-open text-3xl mb-2 block"></i>
              Nenhum produto encontrado nesta movimentação
            </div>
          ) : (
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50 sticky top-0">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produto</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Unidade</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantidade</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Preço Unit.</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {movement.products.map((product, index) => (
                  <tr key={product.id} className={index % 2 === 0 ? 'bg-gray-50' : 'bg-white'}>
                    <td className="px-4 py-3 text-sm text-gray-600">{product.code || '-'}</td>
                    <td className="px-4 py-3 text-sm font-medium text-gray-900">{product.name}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{product.unit}</td>
                    <td className="px-4 py-3 text-sm text-gray-900">{product.quantity.toFixed(2)}</td>
                    <td className="px-4 py-3 text-sm text-gray-900">{formatCurrency(product.unitValue)}</td>
                    <td className="px-4 py-3 text-sm font-semibold text-blue-600">{formatCurrency(product.totalValue)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="p-4 bg-green-50 rounded-lg">
          <div className="flex items-center justify-between">
            <div>
              <h5 className="font-semibold text-gray-700">📦 Resumo da Movimentação</h5>
              <p className="text-sm text-gray-600">Total de {movement.products.length} produto(s) diferentes</p>
            </div>
            <div className="text-right">
              <div className="text-sm text-gray-600">Quantidade Total: <span className="font-medium">{Math.round(totalQuantity)}</span></div>
              <div className="text-lg font-bold text-green-600">Valor Total: {formatCurrency(totalValue)}</div>
            </div>
          </div>
        </div>
      </div>
    </Modal>
  );
}
