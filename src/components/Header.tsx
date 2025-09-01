
import React from 'react';
import { User } from '../types';

interface HeaderProps {
  user: User;
  onLogout: () => void;
  onExport: () => void;
  onManageCompanies: () => void;
}

export default function Header({ user, onLogout, onExport, onManageCompanies }: HeaderProps) {
  return (
    <header className="bg-white/95 backdrop-blur-md shadow-sm border-b border-gray-200 sticky top-0 z-50">
      <div className="max-w-7xl mx-auto px-3 sm:px-4 lg:px-6">
        <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center py-4 gap-4">
          {/* Logo and Title */}
          <div className="flex items-center">
            <div className="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center mr-3 shadow-sm">
              <i className="fas fa-boxes text-white text-lg"></i>
            </div>
            <div>
              <h1 className="text-xl sm:text-2xl font-bold text-gray-900">
                Gestão de Estoque
              </h1>
              <p className="text-xs text-gray-500 hidden sm:block">Sistema inteligente de controle</p>
            </div>
          </div>

          {/* Actions and Status */}
          <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4">
            {/* Status Indicator */}
            <div className="flex items-center text-sm order-2 sm:order-1">
              <div className="w-2 h-2 bg-emerald-500 rounded-full mr-2 animate-pulse"></div>
              <span className="text-gray-600 font-medium">Online</span>
            </div>

            {/* Action Buttons */}
            <div className="flex flex-wrap gap-2 order-1 sm:order-2">
              <button
                onClick={onExport}
                className="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-2 rounded-lg flex items-center text-sm font-medium transition-all duration-200 hover:shadow-md"
              >
                <i className="fas fa-download mr-2"></i>
                <span className="hidden sm:inline">Exportar</span>
              </button>
              
              <button
                onClick={onManageCompanies}
                className="bg-indigo-500 hover:bg-indigo-600 text-white px-3 py-2 rounded-lg flex items-center text-sm font-medium transition-all duration-200 hover:shadow-md"
              >
                <i className="fas fa-building mr-2"></i>
                <span className="hidden sm:inline">Empresas</span>
              </button>
              
              <button
                onClick={onLogout}
                className="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg flex items-center text-sm font-medium transition-all duration-200 hover:shadow-md"
              >
                <i className="fas fa-sign-out-alt mr-2"></i>
                <span className="hidden sm:inline">Sair</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </header>
  );
}
