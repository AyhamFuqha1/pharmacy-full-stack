import { useState, useRef, useEffect } from 'react';
import { Send, Bot, User, Sparkles, AlertCircle, Package } from 'lucide-react';

interface Message {
  id: string;
  type: 'user' | 'ai';
  content: string;
  timestamp: Date;
  alertCard?: {
    type: 'expiry' | 'lowstock';
    title: string;
    message: string;
    icon: any;
  };
}

const quickActions = [
  { id: 1, label: 'Show expiring batches', icon: AlertCircle },
  { id: 2, label: 'Low stock products', icon: Package },
  { id: 3, label: 'Today\'s sales summary', icon: Sparkles },
  { id: 4, label: 'High risk inventory', icon: AlertCircle },
];

const initialMessages: Message[] = [
  {
    id: '1',
    type: 'ai',
    content: 'Hello! I\'m your Pharmacy AI Assistant. I can help you with inventory management, sales insights, expiry alerts, and more. How can I assist you today?',
    timestamp: new Date(),
  },
];

export function ChatPage() {
  const [messages, setMessages] = useState<Message[]>(initialMessages);
  const [input, setInput] = useState('');
  const [isTyping, setIsTyping] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages]);

  const handleSend = () => {
    if (!input.trim()) return;

    // Add user message
    const userMessage: Message = {
      id: Date.now().toString(),
      type: 'user',
      content: input,
      timestamp: new Date(),
    };

    setMessages((prev) => [...prev, userMessage]);
    setInput('');
    setIsTyping(true);

    // Simulate AI response
    setTimeout(() => {
      const aiResponse = generateAIResponse(input);
      setMessages((prev) => [...prev, aiResponse]);
      setIsTyping(false);
    }, 1500);
  };

  const handleQuickAction = (label: string) => {
    setInput(label);
    setTimeout(() => handleSend(), 100);
  };

  const generateAIResponse = (userInput: string): Message => {
    const lowerInput = userInput.toLowerCase();

    if (lowerInput.includes('expir')) {
      return {
        id: Date.now().toString(),
        type: 'ai',
        content: 'I found 3 batches expiring soon that need your attention:',
        timestamp: new Date(),
        alertCard: {
          type: 'expiry',
          title: 'Critical: Batch Expiring Soon',
          message: 'Paracetamol 500mg (BT-2024-001) expires in 8 days. Only 75% sold. Consider promotions or redistribution.',
          icon: AlertCircle,
        },
      };
    }

    if (lowerInput.includes('low stock') || lowerInput.includes('stock')) {
      return {
        id: Date.now().toString(),
        type: 'ai',
        content: 'Here are the products with low stock levels:',
        timestamp: new Date(),
        alertCard: {
          type: 'lowstock',
          title: 'Low Stock Alert',
          message: 'Aspirin 100mg is below minimum threshold. Current stock: 45 units. Reorder recommended.',
          icon: Package,
        },
      };
    }

    if (lowerInput.includes('sales') || lowerInput.includes('summary')) {
      return {
        id: Date.now().toString(),
        type: 'ai',
        content: 'Today\'s sales performance looks great! You\'ve achieved $12,458 in sales (+12.5% vs yesterday). Top selling products: Paracetamol, Ibuprofen, and Metformin. Peak sales hour was 2-3 PM.',
        timestamp: new Date(),
      };
    }

    if (lowerInput.includes('high risk')) {
      return {
        id: Date.now().toString(),
        type: 'ai',
        content: 'You have 12 high-risk inventory items. These are products with low sell-through rates and approaching expiry dates. I recommend reviewing Aspirin 100mg (BT-2024-004) with only 42% sell ratio and 12 days remaining.',
        timestamp: new Date(),
      };
    }

    return {
      id: Date.now().toString(),
      type: 'ai',
      content: 'I can help you with inventory analysis, sales insights, expiry management, and stock alerts. Try asking about specific topics like "show expiring batches" or "low stock products".',
      timestamp: new Date(),
    };
  };

  return (
    <div className="h-full flex flex-col p-8">
      {/* Page Header */}
      <div className="mb-6">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">AI Assistant</h1>
        <p className="text-gray-500">Ask me anything about your pharmacy operations and inventory</p>
      </div>

      {/* Chat Container */}
      <div className="flex-1 bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col overflow-hidden">
        {/* Quick Actions */}
        <div className="px-6 py-4 border-b border-gray-100 bg-gray-50">
          <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Quick Actions</p>
          <div className="flex flex-wrap gap-2">
            {quickActions.map((action) => {
              const Icon = action.icon;
              return (
                <button
                  key={action.id}
                  onClick={() => handleQuickAction(action.label)}
                  className="flex items-center gap-2 px-3 py-2 bg-white border border-gray-200 rounded-xl text-sm text-gray-700 hover:border-teal-500 hover:text-teal-700 hover:bg-teal-50 transition-all"
                >
                  <Icon className="w-4 h-4" />
                  <span>{action.label}</span>
                </button>
              );
            })}
          </div>
        </div>

        {/* Messages */}
        <div className="flex-1 overflow-y-auto p-6 space-y-4">
          {messages.map((message) => (
            <div key={message.id} className={`flex ${message.type === 'user' ? 'justify-end' : 'justify-start'}`}>
              <div className={`flex gap-3 max-w-[70%] ${message.type === 'user' ? 'flex-row-reverse' : 'flex-row'}`}>
                {/* Avatar */}
                <div
                  className={`w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 ${
                    message.type === 'user'
                      ? 'bg-gray-200'
                      : 'bg-gradient-to-br from-teal-500 to-emerald-500'
                  }`}
                >
                  {message.type === 'user' ? (
                    <User className="w-5 h-5 text-gray-700" />
                  ) : (
                    <Bot className="w-5 h-5 text-white" />
                  )}
                </div>

                {/* Message Content */}
                <div>
                  <div
                    className={`rounded-2xl px-5 py-3.5 ${
                      message.type === 'user'
                        ? 'bg-gray-100 text-gray-900'
                        : 'bg-gradient-to-br from-teal-50 to-emerald-50 text-gray-900 border border-teal-100'
                    }`}
                  >
                    <p className="text-sm leading-relaxed">{message.content}</p>
                  </div>

                  {/* Alert Card */}
                  {message.alertCard && (
                    <div className="mt-3 p-4 bg-white border border-gray-200 rounded-xl shadow-sm">
                      <div className="flex items-start gap-3">
                        <div
                          className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${
                            message.alertCard.type === 'expiry'
                              ? 'bg-red-100'
                              : 'bg-orange-100'
                          }`}
                        >
                          <message.alertCard.icon
                            className={`w-5 h-5 ${
                              message.alertCard.type === 'expiry'
                                ? 'text-red-600'
                                : 'text-orange-600'
                            }`}
                          />
                        </div>
                        <div className="flex-1">
                          <h4 className="font-semibold text-gray-900 text-sm mb-1">
                            {message.alertCard.title}
                          </h4>
                          <p className="text-sm text-gray-600 leading-relaxed">
                            {message.alertCard.message}
                          </p>
                        </div>
                      </div>
                    </div>
                  )}

                  <span className="text-xs text-gray-400 mt-1 block">
                    {message.timestamp.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                  </span>
                </div>
              </div>
            </div>
          ))}

          {/* Typing Indicator */}
          {isTyping && (
            <div className="flex justify-start">
              <div className="flex gap-3 max-w-[70%]">
                <div className="w-10 h-10 rounded-full bg-gradient-to-br from-teal-500 to-emerald-500 flex items-center justify-center flex-shrink-0">
                  <Bot className="w-5 h-5 text-white" />
                </div>
                <div className="bg-gradient-to-br from-teal-50 to-emerald-50 border border-teal-100 rounded-2xl px-5 py-3.5">
                  <div className="flex gap-1">
                    <div className="w-2 h-2 bg-teal-500 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
                    <div className="w-2 h-2 bg-teal-500 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
                    <div className="w-2 h-2 bg-teal-500 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
                  </div>
                </div>
              </div>
            </div>
          )}

          <div ref={messagesEndRef} />
        </div>

        {/* Input */}
        <div className="p-6 border-t border-gray-200 bg-white">
          <div className="flex gap-3">
            <input
              type="text"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyPress={(e) => e.key === 'Enter' && handleSend()}
              placeholder="Type your question here..."
              className="flex-1 px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent transition-all"
            />
            <button
              onClick={handleSend}
              disabled={!input.trim()}
              className="px-6 py-3 bg-gradient-to-r from-teal-600 to-emerald-500 text-white rounded-xl hover:from-teal-700 hover:to-emerald-600 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-sm hover:shadow-md flex items-center gap-2"
            >
              <Send className="w-5 h-5" />
              <span className="font-medium">Send</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
